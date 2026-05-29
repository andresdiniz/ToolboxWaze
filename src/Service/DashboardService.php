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
        private readonly Connection     $db,
        private readonly CacheInterface $cache,
    ) {}

    /** KPIs de escolas INEP — adapta-se à estrutura real da tabela */
    public function getEscolaKpis(): array
    {
        return $this->cache->get('dash_escola_kpis', function (ItemInterface $item) {
            $item->expiresAfter(600);
            try {
                return $this->db->fetchAssociative(
                    'SELECT COUNT(*) AS total,
                            COUNT(DISTINCT sigla_uf) AS estados
                     FROM escola_inep'
                ) ?: ['total' => 0, 'estados' => 0];
            } catch (\Throwable) {
                return ['total' => 0, 'estados' => 0];
            }
        });
    }

    /** Quantos estados (UFs distintas) existem no banco de radares */
    public function getEstadosAtivos(): int
    {
        return $this->cache->get('dash_estados_ativos', function (ItemInterface $item) {
            $item->expiresAfter(600);
            return (int) $this->db->fetchOne(
                'SELECT COUNT(DISTINCT sigla_uf) FROM radar_medidor'
            );
        });
    }

    /** KPIs de usuários — baseado na coluna `status` da tabela `user` */
    public function getUsuarioKpis(): array
    {
        return $this->cache->get('dash_usuario_kpis', function (ItemInterface $item) {
            $item->expiresAfter(300);
            return $this->db->fetchAssociative(
                "SELECT COUNT(*)                                    AS total,
                        SUM(status = 'approved')                    AS aprovados,
                        SUM(status = 'pending')                     AS pendentes,
                        SUM(status NOT IN ('pending', 'blocked'))   AS ativos
                 FROM `user`"
            ) ?: ['total' => 0, 'aprovados' => 0, 'pendentes' => 0, 'ativos' => 0];
        });
    }

    /**
     * KPIs de solicitações por tipo + totais gerais.
     * Tabela: solicitacoes | status: PENDENTE / ATENDIDA / RECUSADA
     * Data:   criada_em
     */
    public function getSolicitacaoKpis(): array
    {
        return $this->cache->get('dash_solicitacao_kpis', function (ItemInterface $item) {
            $item->expiresAfter(300);
            try {
                $totais = $this->db->fetchAllAssociative(
                    "SELECT tipo,
                            COUNT(*)                    AS total,
                            SUM(status = 'ATENDIDA')    AS atendidas,
                            SUM(status = 'PENDENTE')    AS pendentes,
                            SUM(status = 'RECUSADA')    AS recusadas
                     FROM solicitacoes
                     GROUP BY tipo
                     ORDER BY total DESC"
                );
                $geral = $this->db->fetchAssociative(
                    "SELECT COUNT(*)                         AS total,
                            SUM(status = 'ATENDIDA')         AS atendidas,
                            SUM(status = 'PENDENTE')         AS pendentes,
                            SUM(status = 'RECUSADA')         AS recusadas,
                            SUM(DATE(criada_em) = CURDATE()) AS hoje
                     FROM solicitacoes"
                ) ?: [];
            } catch (\Throwable) {
                $totais = [];
                $geral  = ['total' => 0, 'atendidas' => 0, 'pendentes' => 0, 'recusadas' => 0, 'hoje' => 0];
            }
            return ['totais' => $totais, 'geral' => $geral];
        });
    }

    /** Solicitações por dia (últimos 30 dias) para gráfico de linha */
    public function getSolicitacoesDiarias(): array
    {
        return $this->cache->get('dash_solic_diarias', function (ItemInterface $item) {
            $item->expiresAfter(300);
            try {
                return $this->db->fetchAllAssociative(
                    'SELECT DATE(criada_em) AS dia, COUNT(*) AS total
                     FROM solicitacoes
                     WHERE criada_em >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                     GROUP BY dia ORDER BY dia'
                );
            } catch (\Throwable) {
                return [];
            }
        });
    }
}
