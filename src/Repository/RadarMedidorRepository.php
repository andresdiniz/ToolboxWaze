<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RadarMedidor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RadarMedidor>
 */
class RadarMedidorRepository extends ServiceEntityRepository
{
    private Connection $db;

    public function __construct(ManagerRegistry $registry, Connection $db)
    {
        parent::__construct($registry, RadarMedidor::class);
        $this->db = $db;
    }

    // =========================================================================
    // ORM helpers
    // =========================================================================

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

    // =========================================================================
    // DBAL – listagem paginada
    // =========================================================================

    public function findPaginated(
        array  $filters,
        ?array $allowedUfs,
        int    $page,
        int    $perPage,
    ): array {
        $offset      = ($page - 1) * $perPage;
        [$where, $params] = $this->buildWhere($filters, $allowedUfs);
        $baseFrom    = $this->buildFrom($filters['serie']);
        $whereClause = $where ? " WHERE $where" : '';
        $dv          = $this->dateConv('rm.data_validade');

        return $this->db->fetchAllAssociative(
            "SELECT DISTINCT
                    rm.id,
                    rm.sigla_uf,
                    rm.uf,
                    rm.municipio,
                    rm.logradouro,
                    rm.nome_empresa,
                    rm.data_ultima_verificacao,
                    rm.data_verificacao_efetiva,
                    rm.data_validade,
                    DATE_FORMAT($dv, '%Y-%m-%d') AS data_validade_iso,
                    rm.situacao,
                    rm.tipo_medidor,
                    rm.link_waze
             FROM radar_medidor rm $baseFrom
             $whereClause
             ORDER BY rm.sigla_uf, rm.municipio, rm.logradouro
             LIMIT $perPage OFFSET $offset",
            $params
        );
    }

    public function countFiltered(array $filters, ?array $allowedUfs): int
    {
        [$where, $params] = $this->buildWhere($filters, $allowedUfs);
        $baseFrom    = $this->buildFrom($filters['serie']);
        $whereClause = $where ? " WHERE $where" : '';

        return (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT rm.id) FROM radar_medidor rm $baseFrom $whereClause",
            $params
        );
    }

    public function findFilterOptions(?array $allowedUfs): array
    {
        $ufsQuery  = 'SELECT DISTINCT sigla_uf FROM radar_medidor WHERE sigla_uf IS NOT NULL AND merged_into_id IS NULL';
        $ufsParams = [];
        if ($allowedUfs !== null && count($allowedUfs) > 0) {
            $ph        = implode(',', array_fill(0, count($allowedUfs), '?'));
            $ufsQuery .= " AND sigla_uf IN ($ph)";
            $ufsParams = $allowedUfs;
        } elseif ($allowedUfs !== null && count($allowedUfs) === 0) {
            $ufsQuery .= ' AND 1=0';
        }
        $ufsQuery .= ' ORDER BY sigla_uf';

        $ufs = array_column($this->db->fetchAllAssociative($ufsQuery, $ufsParams), 'sigla_uf');

        $resultados = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT situacao FROM radar_medidor WHERE situacao IS NOT NULL AND merged_into_id IS NULL ORDER BY situacao'
        ), 'situacao');

        $tipos = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT tipo_medidor FROM radar_medidor WHERE tipo_medidor IS NOT NULL AND merged_into_id IS NULL ORDER BY tipo_medidor'
        ), 'tipo_medidor');

        return compact('ufs', 'resultados', 'tipos');
    }

    public function findRawById(int $id): array|false
    {
        $dv = $this->dateConv('data_validade');
        return $this->db->fetchAssociative(
            "SELECT *, DATE_FORMAT($dv, '%Y-%m-%d') AS data_validade_iso
             FROM radar_medidor WHERE id = ?",
            [$id]
        );
    }

    // =========================================================================
    // Internos
    // =========================================================================

    private function dateConv(string $col): string
    {
        return sprintf("STR_TO_DATE(%s, '%%d/%%m/%%Y')", $col);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function buildFrom(string $serie): string
    {
        return $serie !== '' ? 'LEFT JOIN radar_faixa rf ON rf.radar_medidor_id = rm.id' : '';
    }

    private function buildWhere(array $filters, ?array $allowedUfs): array
    {
        $parts  = [];
        $params = [];

        $parts[] = 'rm.merged_into_id IS NULL';

        if ($allowedUfs !== null) {
            if (count($allowedUfs) === 0) {
                $parts[] = '1=0';
            } else {
                $ph      = implode(',', array_fill(0, count($allowedUfs), '?'));
                $parts[] = "rm.sigla_uf IN ($ph)";
                foreach ($allowedUfs as $v) {
                    $params[] = $v;
                }
            }
        }

        if ($filters['uf'] !== '') {
            $parts[]  = 'rm.sigla_uf = ?';
            $params[] = $filters['uf'];
        }
        if ($filters['municipio'] !== '') {
            $parts[]  = 'rm.municipio LIKE ?';
            $params[] = '%' . $this->escapeLike($filters['municipio']) . '%';
        }
        // resultado agora filtra por situacao
        if (($filters['resultado'] ?? '') !== '') {
            $parts[]  = 'rm.situacao = ?';
            $params[] = $filters['resultado'];
        }
        if ($filters['tipo'] !== '') {
            $parts[]  = 'rm.tipo_medidor = ?';
            $params[] = $filters['tipo'];
        }
        if ($filters['serie'] !== '') {
            $escaped  = $this->escapeLike($filters['serie']);
            $parts[]  = '(rf.numero_serie LIKE ? OR rf.numero_inmetro LIKE ?)';
            $params[] = "%$escaped%";
            $params[] = "%$escaped%";
        }

        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $dv   = $this->dateConv('rm.data_validade');

        switch ($filters['validade']) {
            case 'vencido':
                $parts[]  = "$dv < ?";
                $params[] = $hoje;
                break;
            case 'valido':
                $parts[]  = "$dv >= ?";
                $params[] = $hoje;
                break;
            case '30dias':
                $parts[]  = "$dv >= ? AND $dv <= ?";
                $params[] = $hoje;
                $params[] = $em30;
                break;
            case 'recentes30':
                $ha30dias = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');
                $dve      = $this->dateConv('rm.data_verificacao_efetiva');
                $parts[]  = "rm.data_verificacao_efetiva IS NOT NULL AND $dve >= ? AND $dve <= ?";
                $params[] = $ha30dias;
                $params[] = $hoje;
                break;
        }

        return [implode(' AND ', $parts), $params];
    }
}
