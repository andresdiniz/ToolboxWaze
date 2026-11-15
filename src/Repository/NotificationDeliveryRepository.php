<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class NotificationDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationDelivery::class);
    }

    /** @return list<NotificationDelivery> */
    public function findPending(int $limit = 100): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.status = :status')
            ->andWhere('d.attempts < 5')
            ->andWhere('d.nextAttemptAt IS NULL OR d.nextAttemptAt <= :now')
            ->setParameter('status', NotificationDelivery::ESTADO_PENDENTE)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('d.nextAttemptAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
