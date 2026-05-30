<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class DashboardService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function getEscolaKpis(): array
    {
        try {
            return $this->db->fetchAssociative(
                'SELECT COUNT(*) AS total, COUNT(DISTINCT uf) AS estados FROM escola_inep'
            ) ?: ['total' => 0, 'estados' => 0];
        } catch (\Throwable) {
            return ['total' => 0, 'estados' => 0];
        }
    }

    public function getEstadosAtivos(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(DISTINCT sigla_uf) FROM radar_medidor');
    }

    public function getUsuarioKpis(): array
    {
        try {
            return $this->db->fetchAssociative(
                "SELECT COUNT(*)                                  AS total,
                        SUM(status = 'approved')                  AS aprovados,
                        SUM(status = 'pending')                   AS pendentes,
                        SUM(status NOT IN ('pending','blocked'))  AS ativos
                 FROM `user`"
            ) ?: ['total' => 0, 'aprovados' => 0, 'pendentes' => 0, 'ativos' => 0];
        } catch (\Throwable) {
            return ['total' => 0, 'aprovados' => 0, 'pendentes' => 0, 'ativos' => 0];
        }
    }

    public function getSolicitacaoKpis(): array
    {
        try {
            $totais = $this->db->fetchAllAssociative(
                "SELECT tipo,
                        COUNT(*)                 AS total,
                        SUM(status = 'ATENDIDA') AS atendidas,
                        SUM(status = 'PENDENTE') AS pendentes,
                        SUM(status = 'RECUSADA') AS recusadas
                 FROM solicitacoes
                 GROUP BY tipo ORDER BY total DESC"
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
    }

    public function getSolicitacoesDiarias(): array
    {
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
    }
}
