<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Garante a criação resiliente de radar_edit_log e adiciona colunas de auditoria
 * detalhada em radar_waze_link_log sem quebrar ambientes já existentes.
 */
final class Version20261112200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria/atualiza radar_edit_log e expande colunas de radar_waze_link_log';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migration requer MySQL/MariaDB.'
        );

        // 1. Tabela de histórico de edições manuais de radares (usada por RadarService::saveEdit e edit.html.twig)
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS radar_edit_log (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                radar_medidor_id INT             NOT NULL,
                campo_alterado   VARCHAR(100)    NOT NULL,
                valor_anterior   TEXT            DEFAULT NULL,
                valor_novo       TEXT            DEFAULT NULL,
                editado_por      INT             DEFAULT NULL,
                editado_em       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_rel_radar_edit_log (radar_medidor_id),
                INDEX idx_rel_radar_edit_log_em (editado_em),
                INDEX idx_rel_radar_edit_log_user (editado_por)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Se uma migration anterior tiver criado com o nome 'campo', compatibiliza
        $this->addSql(<<<'SQL'
            SET @tem_campo = (
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'radar_edit_log'
                  AND COLUMN_NAME = 'campo'
            );
            SET @tem_campo_alterado = (
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'radar_edit_log'
                  AND COLUMN_NAME = 'campo_alterado'
            );
            SET @sql_rename = IF(@tem_campo > 0 AND @tem_campo_alterado = 0,
                'ALTER TABLE radar_edit_log CHANGE COLUMN campo campo_alterado VARCHAR(100) NOT NULL',
                'SELECT 1'
            );
            PREPARE stmt_rename FROM @sql_rename;
            EXECUTE stmt_rename;
            DEALLOCATE PREPARE stmt_rename;
        SQL);

        // 2. Colunas necessárias para o salvamento de link Waze (RadarService::saveWazeLink)
        $this->addSql(<<<'SQL'
            SET @sql_waze = IF((
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'radar_waze_link_log'
                  AND COLUMN_NAME = 'waze_link_anterior'
            ) = 0,
            'ALTER TABLE radar_waze_link_log
                ADD COLUMN waze_link_anterior VARCHAR(1000) DEFAULT NULL,
                ADD COLUMN waze_link_novo     VARCHAR(1000) DEFAULT NULL,
                ADD COLUMN motivo             TEXT          DEFAULT NULL,
                ADD COLUMN hazard_id_anterior INT UNSIGNED  DEFAULT NULL,
                ADD COLUMN hazard_id_novo     INT UNSIGNED  DEFAULT NULL',
            'SELECT 1'
            );
            PREPARE stmt_waze FROM @sql_waze;
            EXECUTE stmt_waze;
            DEALLOCATE PREPARE stmt_waze;
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Operação não destrutiva por segurança de auditoria em produção
    }
}
