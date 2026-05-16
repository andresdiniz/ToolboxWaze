<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260516_RadarWazeLink extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabelas radar_waze_link e radar_waze_link_log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE radar_waze_link (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                radar_medidor_id    BIGINT UNSIGNED NOT NULL,
                waze_link           VARCHAR(1000)   NOT NULL,
                permanent_hazard_id INT UNSIGNED    NOT NULL,
                inserted_by         INT             NOT NULL,
                inserted_at         DATETIME        NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_by          INT             DEFAULT NULL,
                updated_at          DATETIME        DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                observacao          LONGTEXT        DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_waze_link_radar (radar_medidor_id),
                CONSTRAINT fk_waze_link_radar  FOREIGN KEY (radar_medidor_id) REFERENCES radar_medidor (id) ON DELETE CASCADE,
                CONSTRAINT fk_waze_link_user   FOREIGN KEY (inserted_by)      REFERENCES user (id),
                CONSTRAINT fk_waze_link_uuser  FOREIGN KEY (updated_by)       REFERENCES user (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE radar_waze_link_log (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                radar_waze_link_id  BIGINT UNSIGNED NOT NULL,
                changed_by          INT             NOT NULL,
                changed_at          DATETIME        NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                campo_alterado      VARCHAR(60)     NOT NULL,
                valor_anterior      LONGTEXT        DEFAULT NULL,
                valor_novo          LONGTEXT        DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_waze_log_link (radar_waze_link_id),
                CONSTRAINT fk_waze_log_link FOREIGN KEY (radar_waze_link_id) REFERENCES radar_waze_link (id) ON DELETE CASCADE,
                CONSTRAINT fk_waze_log_user FOREIGN KEY (changed_by)         REFERENCES user (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS radar_waze_link_log');
        $this->addSql('DROP TABLE IF EXISTS radar_waze_link');
    }
}
