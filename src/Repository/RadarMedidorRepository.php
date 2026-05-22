<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RadarMedidor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;

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

    /**
     * Retorna radares "recentes": verificados nos últimos 30 dias.
     *
     * REGRA DE DATA:
     *   - Usa data_ultima_verificacao (varchar dd/mm/aaaa) quando preenchida.
     *   - Quando vazia/nula, calcula: data_validade (dd/mm/aaaa) - 1 ano.
     *
     * CRITÉRIO "recente":
     *   - A data de verificação efetiva está dentro dos últimos 30 dias
     *     a partir de HOJE (intervalo: hoje - 30 dias  até  hoje).
     *
     * @param string|null $uf    Filtra por UF (opcional).
     * @param int         $days  Janela em dias (padrão 30).
     * @return array
     */
    public function findRecentes(?string $uf = null, int $days = 30): array
    {
        /** @var Connection $conn */
        $conn = $this->getEntityManager()->getConnection();

        // ── Converte varchar dd/mm/aaaa para DATE ────────────────────────────
        // STR_TO_DATE('03/07/2025', '%d/%m/%Y') => 2025-07-03
        // Quando data_ultima_verificacao é NULL ou vazia, usa
        // STR_TO_DATE(data_validade, ...) - INTERVAL 1 YEAR como substituto.
        $sql = <<<SQL
            SELECT *
            FROM radar_medidor
            WHERE (
                -- DATA EFETIVA calculada para comparação
                CASE
                    WHEN data_ultima_verificacao IS NOT NULL
                         AND data_ultima_verificacao <> ''
                    THEN STR_TO_DATE(data_ultima_verificacao, '%d/%m/%Y')
                    WHEN data_validade IS NOT NULL
                         AND data_validade <> ''
                    THEN DATE_SUB(STR_TO_DATE(data_validade, '%d/%m/%Y'), INTERVAL 1 YEAR)
                    ELSE NULL
                END
            ) BETWEEN DATE_SUB(CURDATE(), INTERVAL :days DAY) AND CURDATE()
        SQL;

        $params = ['days' => $days];
        $types  = ['days' => \PDO::PARAM_INT];

        if ($uf !== null && $uf !== '') {
            $sql   .= ' AND sigla_uf = :uf';
            $params['uf'] = strtoupper($uf);
            $types['uf']  = \PDO::PARAM_STR;
        }

        $sql .= ' ORDER BY STR_TO_DATE(IFNULL(NULLIF(data_ultima_verificacao, \'\'), data_validade), \'%d/%m/%Y\') DESC';

        return $conn->fetchAllAssociative($sql, $params, $types);
    }

    /**
     * Conta quantos radares são recentes (mesma regra de findRecentes).
     *
     * @param string|null $uf   Filtra por UF (opcional).
     * @param int         $days Janela em dias (padrão 30).
     * @return int
     */
    public function countRecentes(?string $uf = null, int $days = 30): int
    {
        /** @var Connection $conn */
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT COUNT(*)
            FROM radar_medidor
            WHERE (
                CASE
                    WHEN data_ultima_verificacao IS NOT NULL
                         AND data_ultima_verificacao <> ''
                    THEN STR_TO_DATE(data_ultima_verificacao, '%d/%m/%Y')
                    WHEN data_validade IS NOT NULL
                         AND data_validade <> ''
                    THEN DATE_SUB(STR_TO_DATE(data_validade, '%d/%m/%Y'), INTERVAL 1 YEAR)
                    ELSE NULL
                END
            ) BETWEEN DATE_SUB(CURDATE(), INTERVAL :days DAY) AND CURDATE()
        SQL;

        $params = ['days' => $days];
        $types  = ['days' => \PDO::PARAM_INT];

        if ($uf !== null && $uf !== '') {
            $sql   .= ' AND sigla_uf = :uf';
            $params['uf'] = strtoupper($uf);
            $types['uf']  = \PDO::PARAM_STR;
        }

        return (int) $conn->fetchOne($sql, $params, $types);
    }

    /**
     * Verifica se um radar específico é recente.
     *
     * @param int $radarId ID do registro em radar_medidor.
     * @param int $days    Janela em dias (padrão 30).
     * @return bool
     */
    public function isRecente(int $radarId, int $days = 30): bool
    {
        /** @var Connection $conn */
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT COUNT(*)
            FROM radar_medidor
            WHERE id = :id
              AND (
                CASE
                    WHEN data_ultima_verificacao IS NOT NULL
                         AND data_ultima_verificacao <> ''
                    THEN STR_TO_DATE(data_ultima_verificacao, '%d/%m/%Y')
                    WHEN data_validade IS NOT NULL
                         AND data_validade <> ''
                    THEN DATE_SUB(STR_TO_DATE(data_validade, '%d/%m/%Y'), INTERVAL 1 YEAR)
                    ELSE NULL
                END
              ) BETWEEN DATE_SUB(CURDATE(), INTERVAL :days DAY) AND CURDATE()
        SQL;

        return (int) $conn->fetchOne($sql, ['id' => $radarId, 'days' => $days]) > 0;
    }
}
