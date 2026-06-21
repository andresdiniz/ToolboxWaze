<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela monitoring_event para rastrear eventos de monitoramento do frontend';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS monitoring_event (
                id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type        VARCHAR(64)  NOT NULL,
                page        VARCHAR(512) DEFAULT NULL,
                data        JSON         DEFAULT NULL,
                session_id  VARCHAR(64)  DEFAULT NULL,
                user_id     INT          DEFAULT NULL,
                ip          VARCHAR(45)  DEFAULT NULL,
                user_agent  VARCHAR(512) DEFAULT NULL,
                created_at  DATETIME     NOT NULL,
                INDEX idx_type       (type),
                INDEX idx_created_at (created_at),
                INDEX idx_user_id    (user_id),
                INDEX idx_session    (session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS monitoring_event');
    }
}
