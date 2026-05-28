<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EscolaInep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EscolaInep>
 */
class EscolaInepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EscolaInep::class);
    }
}
