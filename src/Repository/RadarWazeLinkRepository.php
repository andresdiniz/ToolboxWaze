<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RadarWazeLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RadarWazeLink>
 */
class RadarWazeLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RadarWazeLink::class);
    }
}
