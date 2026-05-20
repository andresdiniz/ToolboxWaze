<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona campos do perfil Champ na tabela `user`:
 *   - champ_limit_day        INT NULL  — limite diário de downgrades (null = sem limite)
 *   - champ_limit_month      INT NULL  — limite mensal de downgrades (null = sem limite)
 *   - champ_downgrade_tipos  JSON NULL — tipos de downgrade permitidos (null = todos)
 */
final class Version20260520134600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona colunas champ_limit_day, champ_limit_month e champ_downgrade_tipos na tabela user (perfil Champ).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE `user`
                ADD COLUMN IF NOT EXISTS `champ_limit_day`       INT      DEFAULT NULL COMMENT 'Limite diário de downgrades para o perfil Champ (null = sem limite)',
                ADD COLUMN IF NOT EXISTS `champ_limit_month`     INT      DEFAULT NULL COMMENT 'Limite mensal de downgrades para o perfil Champ (null = sem limite)',
                ADD COLUMN IF NOT EXISTS `champ_downgrade_tipos` LONGTEXT DEFAULT NULL COMMENT 'JSON: tipos de downgrade permitidos ao Champ (null = todos)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE `user`
                DROP COLUMN IF EXISTS `champ_limit_day`,
                DROP COLUMN IF EXISTS `champ_limit_month`,
                DROP COLUMN IF EXISTS `champ_downgrade_tipos`
        SQL);
    }
}
