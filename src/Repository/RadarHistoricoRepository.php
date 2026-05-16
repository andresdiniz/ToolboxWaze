<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RadarHistorico;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RadarHistorico> */
class RadarHistoricoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RadarHistorico::class);
    }

    public function findByRadarId(int $radarId): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.radarMedidor = :id')
            ->setParameter('id', $radarId)
            ->orderBy('h.ano', 'DESC')
            ->addOrderBy('h.dataLaudo', 'DESC')
            ->getQuery()->getResult();
    }

    public function findByAno(string $ano): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.ano = :ano')
            ->setParameter('ano', $ano)
            ->getQuery()->getResult();
    }
}
