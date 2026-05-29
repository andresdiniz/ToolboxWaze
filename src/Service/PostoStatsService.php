<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class PostoStatsService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function getKpis(?array $allowedUfs = null): array
    {
        [$where, $params] = $this->ufWhere($allowedUfs, 'f');
        $wc = $where ? "WHERE $where" : '';

        $row = $this->db->fetchAssociative(
            "SELECT COUNT(DISTINCT f.cnpj) AS total
             FROM fuel_reseller_raw f
             $wc",
            $params
        ) ?: ['total' => 0];

        $comWazeQ = $allowedUfs !== null
            ? "SELECT COUNT(DISTINCT pwl.posto_id)
               FROM posto_waze_link pwl
               INNER JOIN fuel_reseller_raw f ON f.id = pwl.posto_id
               WHERE f.uf IN (" . implode(',', array_fill(0, count($allowedUfs), '?')) . ")"
            : 'SELECT COUNT(DISTINCT posto_id) FROM posto_waze_link';

        $comWaze = (int) $this->db->fetchOne($comWazeQ, $allowedUfs ?? []);
        $total   = (int) ($row['total'] ?? 0);
        $semWaze = $total - $comWaze;
        $pct     = $total > 0 ? round($comWaze / $total * 100, 1) : 0.0;

        return compact('total', 'comWaze', 'semWaze', 'pct');
    }

    public function getAtividadeDiaria(): array
    {
        try {
            return $this->db->fetchAllAssociative(
                "SELECT DATE(created_at) AS dia, COUNT(*) AS cadastros
                 FROM fuel_reseller_raw
                 WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 GROUP BY dia ORDER BY dia"
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function ufWhere(?array $allowedUfs, string $alias = ''): array
    {
        $col = $alias ? "{$alias}.uf" : 'uf';
        if ($allowedUfs === null) { return ['', []]; }
        if (count($allowedUfs) === 0) { return ['1=0', []]; }
        $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
        return ["{$col} IN ($ph)", $allowedUfs];
    }
}
