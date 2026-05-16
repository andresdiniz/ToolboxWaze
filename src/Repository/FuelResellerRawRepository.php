<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FuelResellerRaw;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FuelResellerRaw>
 */
class FuelResellerRawRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FuelResellerRaw::class);
    }

    /**
     * Busca postos por UF e município.
     *
     * @return FuelResellerRaw[]
     */
    public function findByUfAndMunicipio(string $uf, string $municipio): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.uf = :uf')
            ->andWhere('r.municipio = :municipio')
            ->setParameter('uf', $uf)
            ->setParameter('municipio', $municipio)
            ->orderBy('r.razaoSocial', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca por CNPJ exato.
     *
     * @return FuelResellerRaw[]
     */
    public function findByCnpj(string $cnpj): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.cnpj = :cnpj')
            ->setParameter('cnpj', $cnpj)
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca por identity_hash — útil para detectar o mesmo posto com dados diferentes.
     *
     * @return FuelResellerRaw[]
     */
    public function findByIdentityHash(string $identityHash): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.identityHash = :hash')
            ->setParameter('hash', $identityHash)
            ->getQuery()
            ->getResult();
    }

    /**
     * Verifica se uma linha com o mesmo row_hash já existe (evita reimportar snapshot idêntico).
     */
    public function existsByRowHash(string $rowHash): bool
    {
        return (bool) $this->createQueryBuilder('r')
            ->select('1')
            ->andWhere('r.rowHash = :hash')
            ->setParameter('hash', $rowHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
