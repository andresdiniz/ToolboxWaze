<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RadarMedidor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RadarMedidor>
 */
class RadarMedidorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RadarMedidor::class);
    }

    public function findByUf(string $uf): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.uf = :uf')
            ->setParameter('uf', strtoupper($uf))
            ->orderBy('r.municipio', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUfAndMunicipio(string $uf, string $municipio): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.uf = :uf')
            ->andWhere('r.municipio LIKE :municipio')
            ->setParameter('uf', strtoupper($uf))
            ->setParameter('municipio', '%' . $municipio . '%')
            ->orderBy('r.nomeEmpresa', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByNumeroSerie(string $numeroSerie): ?RadarMedidor
    {
        return $this->findOneBy(['numeroSerie' => $numeroSerie]);
    }

    public function findByCnpj(string $cnpj): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.cnpjEmpresa = :cnpj')
            ->setParameter('cnpj', $cnpj)
            ->getQuery()
            ->getResult();
    }

    public function existsByRowHash(string $rowHash): bool
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.rowHash = :hash')
            ->setParameter('hash', $rowHash)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function countByUf(string $uf): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.uf = :uf')
            ->setParameter('uf', strtoupper($uf))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByTipoMedidor(string $tipo): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tipoMedidor LIKE :tipo')
            ->setParameter('tipo', '%' . $tipo . '%')
            ->getQuery()
            ->getResult();
    }
}
