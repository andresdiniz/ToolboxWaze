<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EstabelecimentoSaude;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Regras de reserva de estabelecimentos de saúde: reservar, liberar, renovar e expirar.
 * Só estabelecimentos ATIVOS entram na reserva. Mesmo funcionamento do EscolaReservaService.
 */
#[WithMonologChannel('saude')]
final class SaudeReservaService
{
    /** Minutos sem renovação até uma reserva poder ser tomada por outro usuário. */
    public const TTL_MINUTOS = 30;

    public const LIMITE_MAXIMO = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Reserva até $limite estabelecimentos para o usuário, de forma atômica (lock pessimista).
     *
     * 1. devolve (e renova) as reservas ainda ativas do próprio usuário;
     * 2. completa com livres, pulados ou com reserva expirada de outro usuário.
     *
     * @return array{itens: list<EstabelecimentoSaude>, retomadas: int, novas: int, expiradas_reaproveitadas: int, expira_em: \DateTimeImmutable}
     */
    public function reservar(string $uf, string $username, int $limite, int $ttlMinutos = self::TTL_MINUTOS): array
    {
        $inicio = hrtime(true);
        $limite = max(1, min($limite, self::LIMITE_MAXIMO));
        $agora  = new \DateTimeImmutable();
        $corte  = $agora->modify(sprintf('-%d minutes', $ttlMinutos));

        /** @var array{itens: list<EstabelecimentoSaude>, retomadas: int, novas: int, expiradas_reaproveitadas: int} $resultado */
        $resultado = $this->em->wrapInTransaction(function () use ($uf, $username, $limite, $agora, $corte): array {
            /** @var list<EstabelecimentoSaude> $proprias */
            $proprias = $this->em->createQueryBuilder()
                ->select('e')
                ->from(EstabelecimentoSaude::class, 'e')
                ->where('e.uf = :uf')
                ->andWhere('e.status = :reservado')
                ->andWhere('e.reservadoPara = :username')
                ->setParameter('uf', $uf)
                ->setParameter('reservado', EstabelecimentoSaude::STATUS_RESERVADO)
                ->setParameter('username', $username)
                ->orderBy('e.nomeFantasia', 'ASC')
                ->setMaxResults($limite)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getResult();

            /** @var list<EstabelecimentoSaude> $disponiveis */
            $disponiveis = [];
            $faltam      = $limite - count($proprias);

            if ($faltam > 0) {
                $disponiveis = $this->em->createQueryBuilder()
                    ->select('e')
                    ->from(EstabelecimentoSaude::class, 'e')
                    ->where('e.uf = :uf')
                    ->andWhere('e.ativo = true')
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
                    ->setParameter('reservado', EstabelecimentoSaude::STATUS_RESERVADO)
                    ->setParameter('pulado', EstabelecimentoSaude::STATUS_PULADO)
                    ->setParameter('username', $username)
                    ->setParameter('corte', $corte, Types::DATETIME_IMMUTABLE)
                    ->orderBy('e.nomeFantasia', 'ASC')
                    ->setMaxResults($faltam)
                    ->getQuery()
                    ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                    ->getResult();
            }

            foreach ($proprias as $item) {
                $item->setReservadoEm($agora);
            }

            $reaproveitadas = 0;

            foreach ($disponiveis as $item) {
                if ($item->getStatus() === EstabelecimentoSaude::STATUS_RESERVADO) {
                    ++$reaproveitadas;
                }

                $item->setStatus(EstabelecimentoSaude::STATUS_RESERVADO);
                $item->setReservadoPara($username);
                $item->setReservadoEm($agora);
                $item->setUpdatedAt($agora);
            }

            return [
                'itens'                    => array_merge($proprias, $disponiveis),
                'retomadas'                => count($proprias),
                'novas'                    => count($disponiveis) - $reaproveitadas,
                'expiradas_reaproveitadas' => $reaproveitadas,
            ];
        });

        $resultado['expira_em'] = $agora->modify(sprintf('+%d minutes', $ttlMinutos));

        $this->registrar('reserva', $inicio, [
            'uf'                       => $uf,
            'username'                 => $username,
            'limite'                   => $limite,
            'total'                    => count($resultado['itens']),
            'retomadas'                => $resultado['retomadas'],
            'novas'                    => $resultado['novas'],
            'expiradas_reaproveitadas' => $resultado['expiradas_reaproveitadas'],
        ]);

        return $resultado;
    }

    /** Devolve ao pool as reservas ativas do usuário na UF. Retorna quantas foram liberadas. */
    public function liberar(string $uf, string $username): int
    {
        $inicio = hrtime(true);

        $liberadas = (int) $this->em->createQueryBuilder()
            ->update(EstabelecimentoSaude::class, 'e')
            ->set('e.status', 'NULL')
            ->set('e.reservadoPara', 'NULL')
            ->set('e.reservadoEm', 'NULL')
            ->set('e.updatedAt', ':agora')
            ->where('e.uf = :uf')
            ->andWhere('e.status = :reservado')
            ->andWhere('e.reservadoPara = :username')
            ->setParameter('agora', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setParameter('uf', $uf)
            ->setParameter('reservado', EstabelecimentoSaude::STATUS_RESERVADO)
            ->setParameter('username', $username)
            ->getQuery()
            ->execute();

        $this->registrar('liberacao', $inicio, ['uf' => $uf, 'username' => $username, 'liberadas' => $liberadas]);

        return $liberadas;
    }

    /** Estende o prazo das reservas ativas do usuário (heartbeat enquanto ele trabalha no WME). */
    public function renovar(string $uf, string $username): int
    {
        $inicio = hrtime(true);

        $renovadas = (int) $this->em->createQueryBuilder()
            ->update(EstabelecimentoSaude::class, 'e')
            ->set('e.reservadoEm', ':agora')
            ->where('e.uf = :uf')
            ->andWhere('e.status = :reservado')
            ->andWhere('e.reservadoPara = :username')
            ->setParameter('agora', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setParameter('uf', $uf)
            ->setParameter('reservado', EstabelecimentoSaude::STATUS_RESERVADO)
            ->setParameter('username', $username)
            ->getQuery()
            ->execute();

        $this->registrar('renovacao', $inicio, ['uf' => $uf, 'username' => $username, 'renovadas' => $renovadas]);

        return $renovadas;
    }

    /**
     * Libera todas as reservas vencidas (sem renovação há mais de $ttlMinutos). Usado pelo comando agendado.
     *
     * @return array{liberadas: int, corte: \DateTimeImmutable}
     */
    public function liberarExpiradas(int $ttlMinutos = self::TTL_MINUTOS, ?string $uf = null, bool $dryRun = false): array
    {
        $corte = (new \DateTimeImmutable())->modify(sprintf('-%d minutes', $ttlMinutos));
        $qb    = $this->em->createQueryBuilder();

        if ($dryRun) {
            $qb->select('COUNT(e.id)')->from(EstabelecimentoSaude::class, 'e');
        } else {
            $qb->update(EstabelecimentoSaude::class, 'e')
                ->set('e.status', 'NULL')
                ->set('e.reservadoPara', 'NULL')
                ->set('e.reservadoEm', 'NULL');
        }

        $qb->where('e.status = :reservado')
            ->andWhere('(e.reservadoEm IS NULL OR e.reservadoEm < :corte)')
            ->setParameter('reservado', EstabelecimentoSaude::STATUS_RESERVADO)
            ->setParameter('corte', $corte, Types::DATETIME_IMMUTABLE);

        if ($uf !== null) {
            $qb->andWhere('e.uf = :uf')->setParameter('uf', $uf);
        }

        $query     = $qb->getQuery();
        $liberadas = $dryRun ? (int) $query->getSingleScalarResult() : (int) $query->execute();

        return ['liberadas' => $liberadas, 'corte' => $corte];
    }

    /** @param array<string, mixed> $contexto */
    private function registrar(string $evento, int $inicioNs, array $contexto): void
    {
        $this->logger->info($evento, [
            'evento'     => $evento,
            'duracao_ms' => round((hrtime(true) - $inicioNs) / 1_000_000, 2),
        ] + $contexto);
    }
}
