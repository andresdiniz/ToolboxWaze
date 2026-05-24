<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524_radar_merge extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona coluna merged_into_id em radar_medidor e cria tabela radar_merge_log';
    }

    public function up(Schema $schema): void
    {
        // Coluna que aponta para o sobrevivente (NULL = não foi mesclado)
        $this->addSql(
            "ALTER TABLE radar_medidor
             ADD COLUMN merged_into_id BIGINT UNSIGNED NULL DEFAULT NULL
             AFTER situacao"
        );

        // Tabela de auditoria
        $this->addSql("
            CREATE TABLE radar_merge_log (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                survivor_id     BIGINT UNSIGNED NOT NULL,
                absorbed_id     BIGINT UNSIGNED NOT NULL,
                absorbed_snapshot JSON           NULL,
                fields_overwritten JSON          NULL,
                merged_by       VARCHAR(150)    NOT NULL,
                merged_at       DATETIME        NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_merge_survivor (survivor_id),
                INDEX idx_merge_absorbed (absorbed_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS radar_merge_log');
        $this->addSql('ALTER TABLE radar_medidor DROP COLUMN IF EXISTS merged_into_id');
    }
}
