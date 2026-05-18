<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class PostoStatsService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CacheInterface $cache,
    ) {}

    /** KPIs gerais (cacheado 5 min) */
    public function getKpis(?array $allowedUfs = null): array
    {
        $cacheKey = 'posto_kpis_' . ($allowedUfs !== null ? implode('_', $allowedUfs) : 'all');

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($allowedUfs) {
            $item->expiresAfter(300);

            [$where, $params] = $this->ufWhere($allowedUfs, 'frr');
            $whereClause = $where ? " WHERE $where" : '';

            $total = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM fuel_reseller_raw frr$whereClause",
                $params
            );

            $comWaze = (int) $this->db->fetchOne(
                "SELECT COUNT(DISTINCT pwl.posto_id)
                 FROM posto_waze_link pwl
                 JOIN fuel_reseller_raw frr ON frr.id = pwl.posto_id"
                . ($where ? " WHERE $where" : ''),
                $params
            );

            $semWaze = $total - $comWaze;
            $pct     = $total > 0 ? round(($comWaze / $total) * 100, 1) : 0.0;

            $mesAtual = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM posto_waze_link
                 WHERE inserted_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            );

            $porUf = $this->getCoberturaPorUf($allowedUfs);

            return compact('total', 'comWaze', 'semWaze', 'pct', 'mesAtual', 'porUf');
        });
    }

    /** Cobertura Waze por UF */
    public function getCoberturaPorUf(?array $allowedUfs = null): array
    {
        [$where, $params] = $this->ufWhere($allowedUfs, 'frr');
        $whereClause = $where ? "WHERE $where" : '';

        return $this->db->fetchAllAssociative(
            "SELECT
                frr.uf,
                COUNT(frr.id)                       AS total,
                COUNT(pwl.posto_id)                 AS com_waze,
                COUNT(frr.id) - COUNT(pwl.posto_id) AS sem_waze,
                ROUND(COUNT(pwl.posto_id) / COUNT(frr.id) * 100, 1) AS pct
             FROM fuel_reseller_raw frr
             LEFT JOIN posto_waze_link pwl ON pwl.posto_id = frr.id
             $whereClause
             GROUP BY frr.uf
             ORDER BY frr.uf",
            $params
        );
    }

    /** Atividade diária dos últimos 30 dias */
    public function getAtividadeDiaria(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT DATE(inserted_at) AS dia, COUNT(*) AS cadastros
             FROM posto_waze_link
             WHERE inserted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(inserted_at)
             ORDER BY dia"
        );
    }

    /** Detecta se um permanent_hazard_id já está em outro posto */
    public function findDuplicateHazardId(int $hazardId, int $excludePostoId): ?array
    {
        return $this->db->fetchAssociative(
            'SELECT pwl.posto_id, frr.razao_social, frr.municipio, frr.uf
             FROM posto_waze_link pwl
             JOIN fuel_reseller_raw frr ON frr.id = pwl.posto_id
             WHERE pwl.permanent_hazard_id = ? AND pwl.posto_id <> ?',
            [$hazardId, $excludePostoId]
        ) ?: null;
    }

    /** Gera CSV com BOM UTF-8 (compatível com Excel) */
    public function exportCsv(?array $allowedUfs, array $filters): string
    {
        [$where, $params] = $this->buildExportWhere($allowedUfs, $filters);
        $whereClause = $where ? "WHERE $where" : '';

        $rows = $this->db->fetchAllAssociative(
            "SELECT
                frr.id, frr.cnpj, frr.razao_social, frr.nome_fantasia,
                frr.bandeira, frr.endereco, frr.bairro, frr.municipio, frr.uf, frr.cep,
                frr.autorizacao, frr.status,
                pwl.waze_link, pwl.permanent_hazard_id,
                pwl.inserted_at AS waze_cadastrado_em,
                u.email         AS waze_cadastrado_por
             FROM fuel_reseller_raw frr
             LEFT JOIN posto_waze_link pwl ON pwl.posto_id = frr.id
             LEFT JOIN user u ON u.id = pwl.inserted_by
             $whereClause
             ORDER BY frr.uf, frr.municipio, frr.razao_social",
            $params
        );

        if (empty($rows)) {
            return 'id;cnpj;razao_social;municipio;uf;waze_link' . "\n";
        }

        $csv = implode(';', array_keys($rows[0])) . "\n";
        foreach ($rows as $row) {
            $cells = array_map(
                static fn($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"',
                $row
            );
            $csv .= implode(';', $cells) . "\n";
        }

        return $csv;
    }

    // ------------------------------------------------------------------
    // Helpers privados
    // ------------------------------------------------------------------

    private function ufWhere(?array $allowedUfs, string $alias = 'frr'): array
    {
        if ($allowedUfs === null) {
            return ['', []];
        }
        if (count($allowedUfs) === 0) {
            return ['1=0', []];
        }
        $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
        return ["{$alias}.uf IN ($ph)", $allowedUfs];
    }

    private function buildExportWhere(?array $allowedUfs, array $filters): array
    {
        $parts  = [];
        $params = [];

        if ($allowedUfs !== null) {
            if (count($allowedUfs) === 0) {
                $parts[] = '1=0';
            } else {
                $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
                $parts[] = "frr.uf IN ($ph)";
                foreach ($allowedUfs as $uf) {
                    $params[] = $uf;
                }
            }
        }

        if (!empty($filters['uf'])) {
            $parts[]  = 'frr.uf = ?';
            $params[] = $filters['uf'];
        }
        if (!empty($filters['municipio'])) {
            $parts[]  = 'frr.municipio LIKE ?';
            $params[] = '%' . $filters['municipio'] . '%';
        }
        if (!empty($filters['bandeira'])) {
            $parts[]  = 'frr.bandeira = ?';
            $params[] = $filters['bandeira'];
        }
        if (!empty($filters['sem_waze'])) {
            $parts[] = 'pwl.posto_id IS NULL';
        }
        if (!empty($filters['venue_id'])) {
            $parts[]  = 'pwl.permanent_hazard_id = ?';
            $params[] = (int) $filters['venue_id'];
        }
        if (!empty($filters['status'])) {
            $parts[]  = 'frr.status = ?';
            $params[] = $filters['status'];
        }

        return [implode(' AND ', $parts), $params];
    }
}
