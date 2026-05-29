<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260528235900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela radar_edit_log para registro de edicoes de radares';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS radar_edit_log (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                radar_medidor_id  INT            NOT NULL,
                campo             VARCHAR(100)   NOT NULL,
                valor_anterior    TEXT           DEFAULT NULL,
                valor_novo        TEXT           DEFAULT NULL,
                editado_por       VARCHAR(180)   NOT NULL,
                editado_em        DATETIME       NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_rel_radar_edit_log (radar_medidor_id),
                INDEX idx_rel_radar_edit_log_em (editado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS radar_edit_log');
    }
}
