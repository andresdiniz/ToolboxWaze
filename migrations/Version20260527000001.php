<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona colunas api_token e api_token_generated_at na tabela user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE `user`
             ADD api_token VARCHAR(64) DEFAULT NULL,
             ADD api_token_generated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
             ADD UNIQUE INDEX UNIQ_8D93D649FB2D4546 (api_token)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE `user`
             DROP INDEX UNIQ_8D93D649FB2D4546,
             DROP COLUMN api_token,
             DROP COLUMN api_token_generated_at'
        );
    }
}
