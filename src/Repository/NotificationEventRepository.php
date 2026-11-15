<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class NotificationEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationEvent::class);
    }

    public function findRecent(
        string $type,
        ?string $entityType,
        ?int $entityId,
        int $minutes
    ): ?NotificationEvent {
        $since = (new \DateTimeImmutable())
            ->modify('-' . max(1, $minutes) . ' minutes');

        return $this->createQueryBuilder('e')
            ->where('e.type = :type')
            ->andWhere('e.entityType = :entityType')
            ->andWhere('e.entityId = :entityId')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('type', $type)
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->setParameter('since', $since)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
