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
            ->where('r.siglaUf = :uf')
            ->setParameter('uf', strtoupper($uf))
            ->orderBy('r.municipio', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUfAndMunicipio(string $uf, string $municipio): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.siglaUf = :uf')
            ->andWhere('r.municipio LIKE :municipio')
            ->setParameter('uf', strtoupper($uf))
            ->setParameter('municipio', '%' . $municipio . '%')
            ->orderBy('r.municipio', 'ASC')
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
            ->where('r.siglaUf = :uf')
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

    // -------------------------------------------------------------------------
    // Filtro "recentes" — usa data_verificacao_efetiva calculada na importação
    //
    // Como o campo está em dd/mm/aaaa (varchar), a comparação usa
    // STR_TO_DATE apenas UMA vez por query, não por linha.
    // O índice idx_radar_data_verificacao_efetiva acelera o filtro.
    // -------------------------------------------------------------------------

    /**
     * Retorna radares recentes: data_verificacao_efetiva nos últimos $days dias.
     *
     * @param string|null $uf   Filtra por UF (opcional).
     * @param int         $days Janela em dias (padrão 30).
     * @return RadarMedidor[]
     */
    public function findRecentes(?string $uf = null, int $days = 30): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where(
                'STR_TO_DATE(r.dataVerificacaoEfetiva, \'%d/%m/%Y\') '
                . 'BETWEEN DATE_SUB(CURRENT_DATE(), INTERVAL :days DAY) AND CURRENT_DATE()'
            )
            ->setParameter('days', $days)
            ->orderBy('STR_TO_DATE(r.dataVerificacaoEfetiva, \'%d/%m/%Y\')', 'DESC');

        if ($uf !== null && $uf !== '') {
            $qb->andWhere('r.siglaUf = :uf')
               ->setParameter('uf', strtoupper($uf));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Conta radares recentes.
     *
     * @param string|null $uf   Filtra por UF (opcional).
     * @param int         $days Janela em dias (padrão 30).
     */
    public function countRecentes(?string $uf = null, int $days = 30): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where(
                'STR_TO_DATE(r.dataVerificacaoEfetiva, \'%d/%m/%Y\') '
                . 'BETWEEN DATE_SUB(CURRENT_DATE(), INTERVAL :days DAY) AND CURRENT_DATE()'
            )
            ->setParameter('days', $days);

        if ($uf !== null && $uf !== '') {
            $qb->andWhere('r.siglaUf = :uf')
               ->setParameter('uf', strtoupper($uf));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
