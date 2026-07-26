<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Passo 2 — Índices compostos alinhados aos filtros reais.
 *
 * Queries cobertas:
 *  Q1  findPaginated(): WHERE merged_into_id IS NULL AND sigla_uf = ? AND situacao = ?
 *  Q2  findPaginated(): WHERE merged_into_id IS NULL AND municipio LIKE ?
 *  Q3  findPaginated(): WHERE merged_into_id IS NULL AND tipo_medidor = ?
 *  Q4  findPaginated() recentes30: WHERE data_verificacao_efetiva BETWEEN ? AND ?
 *  Q5  solicitacao listing: WHERE status = ? ORDER BY criado_em DESC
 */
final class Version20260726_PerformanceIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Índices compostos de performance para radar_medidor e solicitacao';
    }

    public function up(Schema $schema): void
    {
        // Q1 — filtro por UF + situação (colunas de alta cardinalidade em conjunto)
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_radar_uf_situacao_merged
             ON radar_medidor (merged_into_id, sigla_uf, situacao)'
        );

        // Q2 — filtro por município (LIKE '%x%' não usa índice B-tree, mas LIKE 'x%' sim)
        //       Índice simples em municipio já acelera ORDER BY e filtros de prefixo
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_radar_municipio
             ON radar_medidor (municipio)'
        );

        // Q3 — filtro por tipo_medidor (coluna de baixa cardinalidade — ajuda o COUNT)
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_radar_tipo_merged
             ON radar_medidor (merged_into_id, tipo_medidor)'
        );

        // Q4 — filtro de recentes30 por data de verificação efetiva
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_radar_verif_efetiva
             ON radar_medidor (data_verificacao_efetiva)'
        );

        // Q5 — listagem de solicitações por status + ordenação por data
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_solic_status_criado
             ON solicitacao (status, criado_em DESC)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_radar_uf_situacao_merged  ON radar_medidor');
        $this->addSql('DROP INDEX IF EXISTS idx_radar_municipio            ON radar_medidor');
        $this->addSql('DROP INDEX IF EXISTS idx_radar_tipo_merged          ON radar_medidor');
        $this->addSql('DROP INDEX IF EXISTS idx_radar_verif_efetiva        ON radar_medidor');
        $this->addSql('DROP INDEX IF EXISTS idx_solic_status_criado        ON solicitacao');
    }
}
