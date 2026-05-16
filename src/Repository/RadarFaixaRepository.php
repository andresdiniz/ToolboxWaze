<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RadarFaixa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RadarFaixa> */
class RadarFaixaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RadarFaixa::class);
    }

    public function findByNumeroSerie(string $serie): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.numeroSerie = :serie')
            ->setParameter('serie', $serie)
            ->getQuery()->getResult();
    }

    public function findByNumeroInmetro(string $inmetro): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.numeroInmetro = :inmetro')
            ->setParameter('inmetro', $inmetro)
            ->getQuery()->getResult();
    }
}
