<?php

namespace App\Repository;

use App\Entity\Notificacao;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class NotificacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notificacao::class);
    }

    public function countNaoLidas(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :u')->andWhere('n.lida = false')
            ->setParameter('u', $user)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return Notificacao[] */
    public function findRecentes(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.usuario = :u')
            ->setParameter('u', $user)
            ->orderBy('n.criadaEm', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function marcarTodasLidas(User $user): void
    {
        $this->createQueryBuilder('n')
            ->update()->set('n.lida', true)
            ->where('n.usuario = :u')->andWhere('n.lida = false')
            ->setParameter('u', $user)
            ->getQuery()->execute();
    }
}
