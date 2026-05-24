<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RadarManual;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RadarManual>
 */
class RadarManualRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RadarManual::class);
    }

    /** Retorna todos os radares pendentes indexados por identity_hash. */
    public function findPendentesByIdentityHash(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->where('r.identityHash IN (:hashes)')
            ->andWhere('r.status = :status')
            ->setParameter('hashes', $hashes)
            ->setParameter('status', RadarManual::STATUS_PENDENTE)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->getIdentityHash()] = $row;
        }

        return $map;
    }

    /** Paginação para a listagem. */
    public function findPaginado(int $page = 1, int $perPage = 25): array
    {
        $offset = ($page - 1) * $perPage;

        return $this->createQueryBuilder('r')
            ->orderBy('r.criadoEm', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countTotal(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPendentes(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status = :s')
            ->setParameter('s', RadarManual::STATUS_PENDENTE)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
