<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria as tabelas posto_waze_link e posto_waze_link_log,
 * espelhando radar_waze_link / radar_waze_link_log.
 *
 * Também adiciona a coluna `observacao` à tabela radar_waze_link_log
 * caso ainda não exista (fix do bug do Twig key "observacao").
 */
final class Version20260518100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Posto Waze Link + Log; fix coluna observacao em radar_waze_link_log';
    }

    public function up(Schema $schema): void
    {
        // ----------------------------------------------------------------
        // Fix: adiciona observacao em radar_waze_link_log se não existir
        // ----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            ALTER TABLE radar_waze_link_log
                ADD COLUMN IF NOT EXISTS observacao LONGTEXT DEFAULT NULL
        SQL);

        // ----------------------------------------------------------------
        // posto_waze_link
        // ----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS posto_waze_link (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                posto_id        BIGINT UNSIGNED NOT NULL,
                waze_link       VARCHAR(1000)   NOT NULL,
                permanent_hazard_id INT UNSIGNED NOT NULL,
                inserted_by     INT             NOT NULL,
                inserted_at     DATETIME        NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_by      INT             DEFAULT NULL,
                updated_at      DATETIME        DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                observacao      LONGTEXT        DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_waze_link_posto (posto_id),
                CONSTRAINT fk_posto_waze_link_posto
                    FOREIGN KEY (posto_id) REFERENCES fuel_reseller_raw (id) ON DELETE CASCADE,
                CONSTRAINT fk_posto_waze_link_inserted_by
                    FOREIGN KEY (inserted_by) REFERENCES user (id),
                CONSTRAINT fk_posto_waze_link_updated_by
                    FOREIGN KEY (updated_by) REFERENCES user (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ----------------------------------------------------------------
        // posto_waze_link_log
        // ----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS posto_waze_link_log (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                posto_waze_link_id  BIGINT UNSIGNED NOT NULL,
                changed_by          INT             NOT NULL,
                changed_at          DATETIME        NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                campo_alterado      VARCHAR(60)     NOT NULL,
                valor_anterior      LONGTEXT        DEFAULT NULL,
                valor_novo          LONGTEXT        DEFAULT NULL,
                observacao          LONGTEXT        DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_posto_waze_log_link (posto_waze_link_id),
                CONSTRAINT fk_posto_waze_link_log_link
                    FOREIGN KEY (posto_waze_link_id) REFERENCES posto_waze_link (id) ON DELETE CASCADE,
                CONSTRAINT fk_posto_waze_link_log_changed_by
                    FOREIGN KEY (changed_by) REFERENCES user (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS posto_waze_link_log');
        $this->addSql('DROP TABLE IF EXISTS posto_waze_link');
        // Não remove a coluna observacao do radar_waze_link_log no rollback
        // para não apagar dados já existentes.
    }
}
