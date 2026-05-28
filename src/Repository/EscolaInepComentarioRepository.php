<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EscolaInepComentario;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EscolaInepComentario>
 */
class EscolaInepComentarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EscolaInepComentario::class);
    }
}
