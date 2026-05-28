<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona campos Waze (link_waze, permanent_hazard_id, link_area_escolar)
 * na tabela escola_inep e cria as tabelas:
 *   - escola_inep_comentario
 *   - escola_inep_waze_link_log
 */
final class Version20260528210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Escola INEP: campos de link Waze, área escolar e tabelas de comentários/log';
    }

    public function up(Schema $schema): void
    {
        // Novos campos na tabela principal
        $this->addSql('ALTER TABLE escola_inep
            ADD link_waze VARCHAR(1000) DEFAULT NULL,
            ADD permanent_hazard_id INT UNSIGNED DEFAULT NULL,
            ADD link_area_escolar VARCHAR(1000) DEFAULT NULL
        ');

        // Tabela de comentários
        $this->addSql('
            CREATE TABLE escola_inep_comentario (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                escola_id  BIGINT UNSIGNED NOT NULL,
                autor_id   INT            NOT NULL,
                texto      LONGTEXT       NOT NULL,
                criado_em  DATETIME       NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                PRIMARY KEY (id),
                INDEX idx_eic_escola (escola_id),
                CONSTRAINT fk_eic_escola FOREIGN KEY (escola_id) REFERENCES escola_inep (id) ON DELETE CASCADE,
                CONSTRAINT fk_eic_autor  FOREIGN KEY (autor_id)  REFERENCES user (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Tabela de log de links
        $this->addSql('
            CREATE TABLE escola_inep_waze_link_log (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                escola_id      BIGINT UNSIGNED NOT NULL,
                campo          VARCHAR(30)     NOT NULL,
                valor_anterior VARCHAR(1000)   DEFAULT NULL,
                valor_novo     VARCHAR(1000)   DEFAULT NULL,
                alterado_por   INT            NOT NULL,
                alterado_em    DATETIME        NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                observacao     LONGTEXT        DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_eiwll_escola (escola_id),
                CONSTRAINT fk_eiwll_escola FOREIGN KEY (escola_id)    REFERENCES escola_inep (id) ON DELETE CASCADE,
                CONSTRAINT fk_eiwll_user  FOREIGN KEY (alterado_por)  REFERENCES user (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE escola_inep_waze_link_log');
        $this->addSql('DROP TABLE escola_inep_comentario');
        $this->addSql('ALTER TABLE escola_inep DROP link_waze, DROP permanent_hazard_id, DROP link_area_escolar');
    }
}
