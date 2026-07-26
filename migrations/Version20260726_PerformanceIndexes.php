<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Índices compostos para as 5 queries mais lentas de radar/solicitações.
 *
 * Alinhados aos filtros reais usados em RadarService::findPaginated()
 * e na listagem de solicitações.
 *
 * A migration usa CREATE INDEX IF NOT EXISTS / DROP INDEX IF EXISTS
 * para ser segura em ambos os ambientes (XAMPP local e Hostinger),
 * independente de qual tabela já existe.
 */
final class Version20260726_PerformanceIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Índices compostos para radar_medidor (Q1-Q4) e solicitacao (Q5) com IF NOT EXISTS';
    }

    public function up(Schema $schema): void
    {
        // Q1 — filtro por UF + situação (excluindo mesclados)
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_radar_uf_situacao_merged
             ON radar_medidor (merged_into_id, sigla_uf, situacao)'
        );

        // Q2 — filtro por município (LIKE + ORDER BY)
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_radar_municipio
             ON radar_medidor (municipio)'
        );

        // Q3 — filtro por tipo de medidor (excluindo mesclados)
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_radar_tipo_merged
             ON radar_medidor (merged_into_id, tipo_medidor)'
        );

        // Q4 — filtro recentes30 (data_verificacao_efetiva BETWEEN ? AND ?)
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_radar_verif_efetiva
             ON radar_medidor (data_verificacao_efetiva)'
        );

        // Q5 — listagem de solicitações por status + data DESC
        //      Protegido com IF EXISTS na tabela para não falhar em ambientes
        //      que ainda não possuem a tabela solicitacao.
        $this->addSql("
            SET @sql = IF(
                (SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitacao') > 0,
                'CREATE INDEX IF NOT EXISTS idx_solic_status_criado
                 ON solicitacao (status, criado_em)',
                'SELECT 1'
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_radar_uf_situacao_merged ON radar_medidor');
        $this->addSql('DROP INDEX IF EXISTS idx_radar_municipio          ON radar_medidor');
        $this->addSql('DROP INDEX IF EXISTS idx_radar_tipo_merged        ON radar_medidor');
        $this->addSql('DROP INDEX IF EXISTS idx_radar_verif_efetiva      ON radar_medidor');

        $this->addSql("
            SET @sql = IF(
                (SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitacao') > 0,
                'DROP INDEX IF EXISTS idx_solic_status_criado ON solicitacao',
                'SELECT 1'
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ");
    }
}
