<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class RadarStatsService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CacheInterface $cache,
    ) {}

    /** KPIs gerais dos radares (cacheado 10 min) */
    public function getKpis(?array $allowedUfs = null): array
    {
        $key = 'radar_kpis_' . ($allowedUfs !== null ? md5(implode('_', $allowedUfs)) : 'all');

        return $this->cache->get($key, function (ItemInterface $item) use ($allowedUfs) {
            $item->expiresAfter(600);

            $hoje = (new \DateTimeImmutable())->format('Y-m-d');
            $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
            $dv   = "STR_TO_DATE(data_validade, '%d/%m/%Y')";

            [$where, $params] = $this->ufWhere($allowedUfs);
            $wc = $where ? "WHERE $where" : '';

            $row = $this->db->fetchAssociative(
                "SELECT COUNT(*) AS total,
                        SUM(situacao = 'APROVADO')  AS aprovados,
                        SUM(situacao = 'REPROVADO') AS reprovados,
                        SUM(data_validade IS NOT NULL AND $dv < ?)    AS vencidos,
                        SUM(data_validade IS NOT NULL AND $dv >= ? AND $dv <= ?) AS vencendo,
                        COUNT(DISTINCT sigla_uf)     AS estados
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

            $total   = (int) ($row['total']     ?? 0);
            $semWaze = $total - $comWaze;
            $pctWaze = $total > 0 ? round($comWaze / $total * 100, 1) : 0.0;

            return array_merge($row ?? [], compact('comWaze', 'semWaze', 'pctWaze'));
        });
    }

    /** Radares por UF (para gráfico de barras) */
    public function getPorUf(?array $allowedUfs = null): array
    {
        $key = 'radar_por_uf_' . ($allowedUfs !== null ? md5(implode('_', $allowedUfs)) : 'all');

        return $this->cache->get($key, function (ItemInterface $item) use ($allowedUfs) {
            $item->expiresAfter(600);
            [$where, $params] = $this->ufWhere($allowedUfs);
            $wc = $where ? "WHERE $where" : '';

            return $this->db->fetchAllAssociative(
                "SELECT sigla_uf AS uf, COUNT(*) AS total,
                        SUM(situacao = 'APROVADO')  AS aprovados,
                        SUM(situacao = 'REPROVADO') AS reprovados
                 FROM radar_medidor $wc
                 GROUP BY sigla_uf ORDER BY sigla_uf",
                $params
            );
        });
    }

    /** Resultado geral (para gráfico de rosca) */
    public function getPorResultado(?array $allowedUfs = null): array
    {
        $key = 'radar_resultado_' . ($allowedUfs !== null ? md5(implode('_', $allowedUfs)) : 'all');

        return $this->cache->get($key, function (ItemInterface $item) use ($allowedUfs) {
            $item->expiresAfter(600);
            [$where, $params] = $this->ufWhere($allowedUfs);
            $wc = $where ? "WHERE $where" : '';

            return $this->db->fetchAllAssociative(
                "SELECT COALESCE(situacao, 'SEM INFO') AS resultado, COUNT(*) AS total
                 FROM radar_medidor $wc
                 GROUP BY situacao ORDER BY total DESC",
                $params
            );
        });
    }

    /** Verificações mensais dos últimos 12 meses (usa radar_historico) */
    public function getVerificacoesMensais(): array
    {
        return $this->cache->get('radar_verif_mensais', function (ItemInterface $item) {
            $item->expiresAfter(600);

            return $this->db->fetchAllAssociative(
                "SELECT DATE_FORMAT(STR_TO_DATE(data_laudo, '%d/%m/%Y'), '%Y-%m') AS mes,
                        COUNT(*) AS total
                 FROM radar_historico
                 WHERE data_laudo IS NOT NULL
                   AND STR_TO_DATE(data_laudo, '%d/%m/%Y') >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY mes ORDER BY mes"
            );
        });
    }

    /** Cobertura Waze por UF dos radares */
    public function getCoberturaWazePorUf(?array $allowedUfs = null): array
    {
        $key = 'radar_cobertura_uf_' . ($allowedUfs !== null ? md5(implode('_', $allowedUfs)) : 'all');

        return $this->cache->get($key, function (ItemInterface $item) use ($allowedUfs) {
            $item->expiresAfter(600);
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
        });
    }

    /** Top radares sem link Waze (APROVADOS prioritários) */
    public function getSemWazePrioritarios(?array $allowedUfs = null, int $limit = 10): array
    {
        [$where, $params] = $this->ufWhere($allowedUfs, 'rm');
        $andUf = $where ? "AND $where" : '';
        $lim   = (int) $limit;

        return $this->db->fetchAllAssociative(
            "SELECT rm.id, rm.sigla_uf, rm.municipio, rm.logradouro,
                    rm.situacao, rm.data_validade
             FROM radar_medidor rm
             LEFT JOIN radar_waze_link rwl ON rwl.radar_medidor_id = rm.id
             WHERE rwl.id IS NULL
               AND rm.situacao = 'APROVADO'
               $andUf
             ORDER BY rm.sigla_uf, rm.municipio
             LIMIT $lim",
            $params
        );
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
