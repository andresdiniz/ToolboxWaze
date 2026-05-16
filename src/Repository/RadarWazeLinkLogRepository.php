<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RadarWazeLinkLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RadarWazeLinkLog>
 */
class RadarWazeLinkLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RadarWazeLinkLog::class);
    }

    /** Retorna o histórico de um link ordenado do mais recente ao mais antigo. */
    public function findByLink(int $linkId): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.radarWazeLink = :id')
            ->setParameter('id', $linkId)
            ->orderBy('l.changedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
