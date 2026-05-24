<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria a tabela radar_manual para radares inseridos manualmente
 * antes de aparecerem na fonte oficial (INMETRO/Google Sheets).
 */
final class Version20260524_RadarManual extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela radar_manual com suporte a merge automático';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS radar_manual (
                id                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                sigla_uf          VARCHAR(2)       NOT NULL,
                municipio         VARCHAR(255)     NOT NULL,
                local_verificacao VARCHAR(500)     NOT NULL,
                tipo_medidor      VARCHAR(100)         NULL,
                velocidade        INT                  NULL,
                sentido           VARCHAR(255)         NULL,
                observacoes       TEXT                 NULL,
                identity_hash     VARCHAR(64)      NOT NULL,
                status            VARCHAR(20)      NOT NULL DEFAULT 'pendente',
                radar_medidor_id  INT UNSIGNED         NULL  COMMENT 'ID em radar_medidor após o merge',
                mesclado_em       DATETIME             NULL,
                criado_em         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                criado_por_id     INT UNSIGNED         NULL,
                PRIMARY KEY (id),
                INDEX idx_radar_manual_identity (identity_hash),
                INDEX idx_radar_manual_status   (status),
                CONSTRAINT fk_rm_criado_por FOREIGN KEY (criado_por_id) REFERENCES user (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS radar_manual');
    }
}
