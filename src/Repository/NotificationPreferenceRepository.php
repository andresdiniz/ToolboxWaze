<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationPreference;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class NotificationPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationPreference::class);
    }

    public function findForUser(User $user): NotificationPreference
    {
        $preference = $this->findOneBy(['user' => $user]);

        if ($preference instanceof NotificationPreference) {
            return $preference;
        }

        $preference = new NotificationPreference($user);
        $this->getEntityManager()->persist($preference);
        $this->getEntityManager()->flush();

        return $preference;
    }

    /** @return list<NotificationPreference> */
    public function findEmailSubscribers(string $type): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.user', 'u')
            ->where('p.emailEnabled = TRUE')
            ->andWhere('JSON_CONTAINS(p.types, :type) = 1')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere("u.email <> ''")
            ->setParameter('type', json_encode($type, JSON_THROW_ON_ERROR))
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
