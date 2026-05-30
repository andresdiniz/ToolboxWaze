<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class RadarStatsService
{
    /**
     * Termos que identificam radar APROVADO (case-insensitive, parcial).
     * Cobre: Aprovado, APROVADO, aprovado, APTO, Apto, Conforme, CONFORME etc.
     */
    private const APROVADO_LIKE  = ['%APROV%', '%APTO%', '%CONFORM%'];

    /**
     * Termos que identificam radar REPROVADO (case-insensitive, parcial).
     * Cobre: Reprovado, REPROVADO, NAO APTO, Não Apto, Não Conforme etc.
     */
    private const REPROVADO_LIKE = ['%REPROV%', '%NAO APTO%', '%NÃO APTO%', '%INCONF%', '%N\u00c3O CONF%'];

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function getKpis(?array $allowedUfs = null): array
    {
        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $dv   = "STR_TO_DATE(data_validade, '%d/%m/%Y')";

        [$where, $params] = $this->ufWhere($allowedUfs);
        $wc = $where ? "WHERE $where" : '';

        $aprovExpr  = $this->likeOr('situacao', self::APROVADO_LIKE);
        $reprovExpr = $this->likeOr('situacao', self::REPROVADO_LIKE);

        $row = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    SUM($aprovExpr)  AS aprovados,
                    SUM($reprovExpr) AS reprovados,
                    SUM(data_validade IS NOT NULL AND $dv < ?)    AS vencidos,
                    SUM(data_validade IS NOT NULL AND $dv >= ? AND $dv <= ?) AS vencendo,
                    COUNT(DISTINCT sigla_uf) AS estados
             FROM radar_medidor $wc",
            array_merge([$hoje, $hoje, $em30], $params)
        );

        $comWazeQuery = $allowedUfs !== null
            ? 'SELECT COUNT(DISTINCT rwl.radar_medidor_id)
               FROM radar_waze_link rwl
               INNER JOIN radar_medidor rm ON rm.id = rwl.radar_medidor_id
               WHERE rm.sigla_uf IN (' . implode(',', array_fill(0, count($allowedUfs), '?')) . ')'
            : 'SELECT COUNT(DISTINCT rwl.radar_medidor_id) FROM radar_waze_link rwl';

        $comWaze = (int) $this->db->fetchOne($comWazeQuery, $allowedUfs ?? []);
        $total   = (int) ($row['total'] ?? 0);
        $semWaze = $total - $comWaze;
        $pctWaze = $total > 0 ? round($comWaze / $total * 100, 1) : 0.0;

        return array_merge($row ?? [], compact('comWaze', 'semWaze', 'pctWaze'));
    }

    public function getPorUf(?array $allowedUfs = null): array
    {
        [$where, $params] = $this->ufWhere($allowedUfs);
        $wc = $where ? "WHERE $where" : '';

        $aprovExpr  = $this->likeOr('situacao', self::APROVADO_LIKE);
        $reprovExpr = $this->likeOr('situacao', self::REPROVADO_LIKE);

        return $this->db->fetchAllAssociative(
            "SELECT sigla_uf AS uf, COUNT(*) AS total,
                    SUM($aprovExpr)  AS aprovados,
                    SUM($reprovExpr) AS reprovados
             FROM radar_medidor $wc
             GROUP BY sigla_uf ORDER BY sigla_uf",
            $params
        );
    }

    public function getPorResultado(?array $allowedUfs = null): array
    {
        [$where, $params] = $this->ufWhere($allowedUfs);
        $wc = $where ? "WHERE $where" : '';

        return $this->db->fetchAllAssociative(
            "SELECT COALESCE(UPPER(situacao), 'SEM INFO') AS resultado, COUNT(*) AS total
             FROM radar_medidor $wc
             GROUP BY UPPER(situacao) ORDER BY total DESC",
            $params
        );
    }

    public function getVerificacoesMensais(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT DATE_FORMAT(STR_TO_DATE(data_laudo, '%d/%m/%Y'), '%Y-%m') AS mes,
                    COUNT(*) AS total
             FROM radar_historico
             WHERE data_laudo IS NOT NULL
               AND STR_TO_DATE(data_laudo, '%d/%m/%Y') IS NOT NULL
               AND STR_TO_DATE(data_laudo, '%d/%m/%Y') BETWEEN DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND CURDATE()
             GROUP BY mes ORDER BY mes"
        );
    }

    public function getCoberturaWazePorUf(?array $allowedUfs = null): array
    {
        [$where, $params] = $this->ufWhere($allowedUfs, 'rm');
        $wc = $where ? "WHERE $where" : '';

        return $this->db->fetchAllAssociative(
            "SELECT rm.sigla_uf AS uf,
                    COUNT(rm.id)                 AS total,
                    COUNT(rwl.id)                AS com_waze,
                    COUNT(rm.id) - COUNT(rwl.id) AS sem_waze,
                    ROUND(COUNT(rwl.id) / COUNT(rm.id) * 100, 1) AS pct
             FROM radar_medidor rm
             LEFT JOIN radar_waze_link rwl ON rwl.radar_medidor_id = rm.id
             $wc
             GROUP BY rm.sigla_uf
             ORDER BY pct ASC, sem_waze DESC",
            $params
        );
    }

    public function getSemWazePrioritarios(?array $allowedUfs = null, int $limit = 10): array
    {
        [$where, $params] = $this->ufWhere($allowedUfs, 'rm');
        $andUf = $where ? "AND $where" : '';
        $lim   = (int) $limit;

        $aprovExpr = $this->likeOr('UPPER(rm.situacao)', [
            '%APROV%', '%APTO%', '%CONFORM%',
        ]);

        return $this->db->fetchAllAssociative(
            "SELECT rm.id, rm.sigla_uf, rm.municipio, rm.logradouro,
                    rm.situacao, rm.data_validade
             FROM radar_medidor rm
             LEFT JOIN radar_waze_link rwl ON rwl.radar_medidor_id = rm.id
             WHERE rwl.id IS NULL
               AND $aprovExpr
               $andUf
             ORDER BY rm.sigla_uf, rm.municipio
             LIMIT $lim",
            $params
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Gera expressão SQL: UPPER(col) LIKE '%X%' OR UPPER(col) LIKE '%Y%' ...
     * Envolve tudo em parênteses e usa IS NOT NULL para excluir NULLs.
     */
    private function likeOr(string $col, array $patterns): string
    {
        $parts = [];
        foreach ($patterns as $p) {
            $escaped = addslashes($p);
            $parts[] = "UPPER($col) LIKE '$escaped'";
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    private function ufWhere(?array $allowedUfs, string $alias = ''): array
    {
        $col = $alias ? "{$alias}.sigla_uf" : 'sigla_uf';
        if ($allowedUfs === null) { return ['', []]; }
        if (count($allowedUfs) === 0) { return ['1=0', []]; }
        $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
        return ["{$col} IN ($ph)", $allowedUfs];
    }
}
