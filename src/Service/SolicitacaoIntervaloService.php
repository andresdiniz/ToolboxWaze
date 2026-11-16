<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FormBuilder;
use App\Entity\Solicitacao;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Shared by website and WME API. Call lock() within the write transaction. */
final class SolicitacaoIntervaloService
{
    public const SEGUNDOS = 120;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function lock(User $user): void
    {
        $connection = $this->em->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('O intervalo deve ser validado dentro de uma transação.');
        }
        // Same lock already used by WmeApiController: serializes site and API.
        $connection->executeQuery(
            'SELECT id FROM `user` WHERE id = ? FOR UPDATE',
            [$user->getId()]
        )->fetchOne();
    }

    /** @return array{aguardarSegundos:int,proximoEnvioEm:?string} */
    public function status(User $user, FormBuilder $form): array
    {
        $row = $this->em->getRepository(Solicitacao::class)
            ->createQueryBuilder('s')
            ->select('s.criadaEm AS criadaEm')
            ->where('s.formulario = :form')
            ->andWhere('LOWER(s.solicitanteEmail) = :email')
            ->setParameter('form', $form)
            ->setParameter('email', mb_strtolower($user->getEmail()))
            ->orderBy('s.criadaEm', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if ($row === null) {
            return ['aguardarSegundos' => 0, 'proximoEnvioEm' => null];
        }
        $created = $row['criadaEm'];
        if (!$created instanceof \DateTimeInterface) {
            $created = new \DateTimeImmutable((string) $created);
        }
        $next = \DateTimeImmutable::createFromInterface($created)
            ->modify('+' . self::SEGUNDOS . ' seconds');
        return [
            'aguardarSegundos' => max(0, $next->getTimestamp() - time()),
            'proximoEnvioEm' => $next->format(DATE_ATOM),
        ];
    }
}
