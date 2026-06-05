<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260605_radar_edit_log extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela radar_edit_log para auditoria de edições manuais de radares';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS radar_edit_log (
                id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                radar_medidor_id INT UNSIGNED   NOT NULL,
                campo_alterado  VARCHAR(100)    NOT NULL,
                valor_anterior  TEXT            NULL,
                valor_novo      TEXT            NULL,
                editado_por     INT             NULL,
                editado_em      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_rel_radar  (radar_medidor_id),
                INDEX idx_editado_em (editado_em),
                CONSTRAINT fk_rel_radar_edit_log
                    FOREIGN KEY (radar_medidor_id)
                    REFERENCES radar_medidor (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS radar_edit_log');
    }
}
