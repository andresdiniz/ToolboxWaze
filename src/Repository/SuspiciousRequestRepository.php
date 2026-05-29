<?php

namespace App\Repository;

use App\Entity\SuspiciousRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SuspiciousRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SuspiciousRequest::class);
    }

    public function countRecentByIp(string $ip, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.ip = :ip')
            ->andWhere('s.createdAt >= :since')
            ->setParameter('ip', $ip)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findSuspected(int $limit = 100): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findBlockedIps(): array
    {
        return $this->createQueryBuilder('s')
            ->select('s.ip, COUNT(s.id) as total')
            ->where('s.action = :action')
            ->setParameter('action', 'block')
            ->groupBy('s.ip')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
