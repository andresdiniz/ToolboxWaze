<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260516_auth extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela user com campos de autenticação, Google OAuth e aprovação';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE `user` (
                id              INT AUTO_INCREMENT NOT NULL,
                email           VARCHAR(180) NOT NULL,
                name            VARCHAR(120) NOT NULL,
                roles           JSON NOT NULL,
                password        VARCHAR(255) DEFAULT NULL,
                status          VARCHAR(20) NOT NULL DEFAULT 'pending',
                google_id       VARCHAR(255) DEFAULT NULL,
                avatar_url      VARCHAR(255) DEFAULT NULL,
                reset_token         VARCHAR(255) DEFAULT NULL,
                reset_token_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at      DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                approved_at     DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                last_login_at   DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_8D93D649E7927C74 (email),
                INDEX IDX_STATUS (status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `user`');
    }
}
