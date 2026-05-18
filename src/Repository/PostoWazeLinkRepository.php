<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PostoWazeLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PostoWazeLink>
 */
class PostoWazeLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostoWazeLink::class);
    }

    /** Busca o link ativo para um dado posto_id. */
    public function findByPostoId(int $postoId): ?PostoWazeLink
    {
        return $this->findOneBy(['posto' => $postoId]);
    }
}
