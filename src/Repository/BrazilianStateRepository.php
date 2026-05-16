<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BrazilianState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrazilianState>
 */
class BrazilianStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrazilianState::class);
    }

    /** Retorna todos os estados ordenados pela sigla UF. */
    public function findAllOrderedByUf(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.uf', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Retorna apenas as siglas (strings), em ordem alfabética. */
    public function findAllUfs(): array
    {
        return array_column(
            $this->createQueryBuilder('s')
                ->select('s.uf')
                ->orderBy('s.uf', 'ASC')
                ->getQuery()
                ->getArrayResult(),
            'uf'
        );
    }

    public function findByUf(string $uf): ?BrazilianState
    {
        return $this->findOneBy(['uf' => strtoupper($uf)]);
    }

    public function findByRegion(string $region): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.region = :region')
            ->setParameter('region', $region)
            ->orderBy('s.uf', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
