<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EscolaInepWazeLinkLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EscolaInepWazeLinkLog>
 */
class EscolaInepWazeLinkLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EscolaInepWazeLinkLog::class);
    }
}
