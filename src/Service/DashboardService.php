<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Agrega KPIs globais para o dashboard principal.
 * Todos os métodos são cacheados (TTL 10 min).
 */
final class DashboardService
{
    public function __construct(
        private readonly Connection    $db,
        private readonly CacheInterface $cache,
    ) {}

    /** KPIs de escolas INEP */
    public function getEscolaKpis(): array
    {
        return $this->cache->get('dash_escola_kpis', function (ItemInterface $item) {
            $item->expiresAfter(600);
            return $this->db->fetchAssociative(
                "SELECT COUNT(*)                              AS total,
                        COUNT(DISTINCT sigla_uf)              AS estados,
                        SUM(situacao = 'ATIVA')               AS ativas,
                        SUM(situacao != 'ATIVA')              AS inativas,
                        SUM(waze_place_id IS NOT NULL)        AS com_waze,
                        SUM(waze_place_id IS NULL)            AS sem_waze
                 FROM escola_inep"
            ) ?: [];
        });
    }

    /** Quantos estados (UFs distintas) existem no banco de radares */
    public function getEstadosAtivos(): int
    {
        return $this->cache->get('dash_estados_ativos', function (ItemInterface $item) {
            $item->expiresAfter(600);
            return (int) $this->db->fetchOne('SELECT COUNT(DISTINCT sigla_uf) FROM radar_medidor');
        });
    }

    /** KPIs de usuários */
    public function getUsuarioKpis(): array
    {
        return $this->cache->get('dash_usuario_kpis', function (ItemInterface $item) {
            $item->expiresAfter(300);
            return $this->db->fetchAssociative(
                "SELECT COUNT(*)                         AS total,
                        SUM(is_verified = 1)             AS verificados,
                        SUM(is_verified = 0)             AS pendentes,
                        SUM(is_active   = 1)             AS ativos
                 FROM user"
            ) ?: [];
        });
    }

    /** KPIs de solicitações por tipo + atendidas */
    public function getSolicitacaoKpis(): array
    {
        return $this->cache->get('dash_solicitacao_kpis', function (ItemInterface $item) {
            $item->expiresAfter(300);

            $totais = $this->db->fetchAllAssociative(
                "SELECT tipo, COUNT(*) AS total,
                        SUM(status = 'ATENDIDA')  AS atendidas,
                        SUM(status = 'PENDENTE')  AS pendentes,
                        SUM(status = 'RECUSADA')  AS recusadas
                 FROM solicitacao
                 GROUP BY tipo
                 ORDER BY total DESC"
            );

            $geral = $this->db->fetchAssociative(
                "SELECT COUNT(*)                       AS total,
                        SUM(status = 'ATENDIDA')       AS atendidas,
                        SUM(status = 'PENDENTE')       AS pendentes,
                        SUM(status = 'RECUSADA')       AS recusadas,
                        SUM(DATE(criado_em) = CURDATE()) AS hoje
                 FROM solicitacao"
            ) ?: [];

            return ['totais' => $totais, 'geral' => $geral];
        });
    }

    /** Solicitações por dia (últimos 30 dias) para gráfico de linha */
    public function getSolicitacoesDiarias(): array
    {
        return $this->cache->get('dash_solic_diarias', function (ItemInterface $item) {
            $item->expiresAfter(300);
            return $this->db->fetchAllAssociative(
                "SELECT DATE(criado_em) AS dia, COUNT(*) AS total
                 FROM solicitacao
                 WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 GROUP BY dia ORDER BY dia"
            );
        });
    }
}
