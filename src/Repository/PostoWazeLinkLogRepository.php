<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PostoWazeLinkLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PostoWazeLinkLog>
 */
class PostoWazeLinkLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostoWazeLinkLog::class);
    }

    /**
     * Retorna todos os logs de um PostoWazeLink, do mais recente ao mais antigo.
     *
     * @return PostoWazeLinkLog[]
     */
    public function findByLinkId(int $linkId): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.postoWazeLink = :id')
            ->setParameter('id', $linkId)
            ->orderBy('l.changedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
