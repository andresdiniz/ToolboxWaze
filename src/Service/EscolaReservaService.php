<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EscolaInep;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Regras de reserva de escolas: reservar, liberar, renovar e expirar.
 *
 * Toda operação grava uma linha JSON no canal "escolas" (var/log/escolas-*.log) com a duração em
 * milissegundos e a consulta DQL executada, para análise posterior (ver escolas-reserva-setup.md).
 */
final class EscolaReservaService
{
    /** Minutos sem renovação até uma reserva poder ser tomada por outro usuário. */
    public const TTL_MINUTOS = 30;

    public const LIMITE_MAXIMO = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.escolas')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Reserva até $limite escolas para o usuário, de forma atômica (lock pessimista dentro de transação).
     *
     * 1. devolve (e renova) as reservas ainda ativas do próprio usuário, útil ao trocar de navegador/PC;
     * 2. completa com escolas livres, puladas ou com reserva expirada de outro usuário.
     *
     * @return array{escolas: list<EscolaInep>, retomadas: int, novas: int, expiradas_reaproveitadas: int, expira_em: \DateTimeImmutable}
     */
    public function reservar(string $uf, string $username, int $limite, int $ttlMinutos = self::TTL_MINUTOS): array
    {
        $inicio = hrtime(true);
        $limite = max(1, min($limite, self::LIMITE_MAXIMO));
        $agora = new \DateTimeImmutable();
        $corte = $agora->modify(sprintf('-%d minutes', $ttlMinutos));
        $consulta = '';

        /** @var array{escolas: list<EscolaInep>, retomadas: int, novas: int, expiradas_reaproveitadas: int} $resultado */
        $resultado = $this->em->wrapInTransaction(function () use ($uf, $username, $limite, $agora, $corte, &$consulta): array {
            /** @var list<EscolaInep> $proprias */
            $proprias = $this->em->createQueryBuilder()
                ->select('e')
                ->from(EscolaInep::class, 'e')
                ->where('e.uf = :uf')
                ->andWhere('e.status = :reservado')
                ->andWhere('e.reservadoPara = :username')
                ->setParameter('uf', $uf)
                ->setParameter('reservado', EscolaInep::STATUS_RESERVADO)
                ->setParameter('username', $username)
                ->orderBy('e.escola', 'ASC')
                ->setMaxResults($limite)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getResult();

            /** @var list<EscolaInep> $disponiveis */
            $disponiveis = [];
            $faltam = $limite - count($proprias);

            if ($faltam > 0) {
                $query = $this->em->createQueryBuilder()
                    ->select('e')
                    ->from(EscolaInep::class, 'e')
                    ->where('e.uf = :uf')
                    ->andWhere('(
                        e.status IS NULL
                        OR e.status = :pulado
                        OR (
                            e.status = :reservado
                            AND e.reservadoPara <> :username
                            AND (e.reservadoEm IS NULL OR e.reservadoEm < :corte)
                        )
                    )')
                    ->setParameter('uf', $uf)
                    ->setParameter('reservado', EscolaInep::STATUS_RESERVADO)
                    ->setParameter('pulado', EscolaInep::STATUS_PULADO)
                    ->setParameter('username', $username)
                    ->setParameter('corte', $corte, Types::DATETIME_IMMUTABLE)
                    ->orderBy('e.escola', 'ASC')
                    ->setMaxResults($faltam)
                    ->getQuery()
                    ->setLockMode(LockMode::PESSIMISTIC_WRITE);

                $consulta = $query->getDQL();
                $disponiveis = $query->getResult();
            }

            foreach ($proprias as $escola) {
                $escola->setReservadoEm($agora);
            }

            $reaproveitadas = 0;

            foreach ($disponiveis as $escola) {
                if ($escola->getStatus() === EscolaInep::STATUS_RESERVADO) {
                    ++$reaproveitadas;
                }

                $escola->setStatus(EscolaInep::STATUS_RESERVADO);
                $escola->setReservadoPara($username);
                $escola->setReservadoEm($agora);
                $escola->setUpdatedAt($agora);
            }

            return [
                'escolas' => array_merge($proprias, $disponiveis),
                'retomadas' => count($proprias),
                'novas' => count($disponiveis) - $reaproveitadas,
                'expiradas_reaproveitadas' => $reaproveitadas,
            ];
        });

        $resultado['expira_em'] = $agora->modify(sprintf('+%d minutes', $ttlMinutos));

        $this->registrar('reserva', $inicio, [
            'uf' => $uf,
            'username' => $username,
            'limite' => $limite,
            'ttl_minutos' => $ttlMinutos,
            'total' => count($resultado['escolas']),
            'retomadas' => $resultado['retomadas'],
            'novas' => $resultado['novas'],
            'expiradas_reaproveitadas' => $resultado['expiradas_reaproveitadas'],
            'consulta' => $consulta,
        ]);

        return $resultado;
    }

    /**
     * Devolve ao pool as reservas ativas do usuário na UF. Retorna quantas foram liberadas.
     */
    public function liberar(string $uf, string $username): int
    {
        $inicio = hrtime(true);

        $query = $this->em->createQueryBuilder()
            ->update(EscolaInep::class, 'e')
            ->set('e.status', 'NULL')
            ->set('e.reservadoPara', 'NULL')
            ->set('e.reservadoEm', 'NULL')
            ->set('e.updatedAt', ':agora')
            ->where('e.uf = :uf')
            ->andWhere('e.status = :reservado')
            ->andWhere('e.reservadoPara = :username')
            ->setParameter('agora', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setParameter('uf', $uf)
            ->setParameter('reservado', EscolaInep::STATUS_RESERVADO)
            ->setParameter('username', $username)
            ->getQuery();

        $liberadas = (int) $query->execute();

        $this->registrar('liberacao', $inicio, [
            'uf' => $uf,
            'username' => $username,
            'liberadas' => $liberadas,
            'consulta' => $query->getDQL(),
        ]);

        return $liberadas;
    }

    /**
     * Estende o prazo das reservas ativas do usuário (heartbeat enquanto ele trabalha no WME).
     */
    public function renovar(string $uf, string $username): int
    {
        $inicio = hrtime(true);

        $query = $this->em->createQueryBuilder()
            ->update(EscolaInep::class, 'e')
            ->set('e.reservadoEm', ':agora')
            ->where('e.uf = :uf')
            ->andWhere('e.status = :reservado')
            ->andWhere('e.reservadoPara = :username')
            ->setParameter('agora', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setParameter('uf', $uf)
            ->setParameter('reservado', EscolaInep::STATUS_RESERVADO)
            ->setParameter('username', $username)
            ->getQuery();

        $renovadas = (int) $query->execute();

        $this->registrar('renovacao', $inicio, [
            'uf' => $uf,
            'username' => $username,
            'renovadas' => $renovadas,
            'consulta' => $query->getDQL(),
        ]);

        return $renovadas;
    }

    /**
     * Libera todas as reservas vencidas (sem renovação há mais de $ttlMinutos). Usado pelo comando agendado.
     * Reservas antigas sem data (reservado_em NULL) também são consideradas vencidas.
     *
     * @return array{liberadas: int, corte: \DateTimeImmutable, dql: string}
     */
    public function liberarExpiradas(int $ttlMinutos = self::TTL_MINUTOS, ?string $uf = null, bool $dryRun = false): array
    {
        $corte = (new \DateTimeImmutable())->modify(sprintf('-%d minutes', $ttlMinutos));
        $qb = $this->em->createQueryBuilder();

        if ($dryRun) {
            $qb->select('COUNT(e.id)')->from(EscolaInep::class, 'e');
        } else {
            $qb->update(EscolaInep::class, 'e')
                ->set('e.status', 'NULL')
                ->set('e.reservadoPara', 'NULL')
                ->set('e.reservadoEm', 'NULL');
        }

        $qb->where('e.status = :reservado')
            ->andWhere('(e.reservadoEm IS NULL OR e.reservadoEm < :corte)')
            ->setParameter('reservado', EscolaInep::STATUS_RESERVADO)
            ->setParameter('corte', $corte, Types::DATETIME_IMMUTABLE);

        if ($uf !== null) {
            $qb->andWhere('e.uf = :uf')->setParameter('uf', $uf);
        }

        $query = $qb->getQuery();
        $liberadas = $dryRun ? (int) $query->getSingleScalarResult() : (int) $query->execute();

        return ['liberadas' => $liberadas, 'corte' => $corte, 'dql' => $query->getDQL()];
    }

    /**
     * @param array<string, mixed> $contexto
     */
    private function registrar(string $evento, int $inicioNs, array $contexto): void
    {
        $this->logger->info($evento, [
            'evento' => $evento,
            'duracao_ms' => round((hrtime(true) - $inicioNs) / 1_000_000, 2),
        ] + $contexto);
    }
}
