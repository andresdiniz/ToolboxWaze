<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RadarWazeLink;
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

    /**
     * Retorna todos os logs de um RadarWazeLink, ordenados do mais recente
     * para o mais antigo.
     *
     * @return RadarWazeLinkLog[]
     */
    public function findByLink(RadarWazeLink $link): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.radarWazeLink = :link')
            ->setParameter('link', $link)
            ->orderBy('l.changedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
