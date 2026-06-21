<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renomeia a coluna `local_verificacao` para `logradouro` na tabela `radar_medidor`,
 * caso ela ainda exista com o nome antigo.
 *
 * A migration é idempotente: se a coluna já se chama `logradouro` (deploy anterior
 * já fez a renomeação), o bloco dentro do IF é pulado sem erros.
 */
final class Version20260621_FixLocalVerificacao extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomeia radar_medidor.local_verificacao → logradouro (corrige SQLSTATE 42S22)';
    }

    public function up(Schema $schema): void
    {
        // Verifica se a coluna antiga ainda existe antes de tentar renomear
        $this->addSql(<<<'SQL'
            SET @col_exists = (
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'radar_medidor'
                  AND COLUMN_NAME  = 'local_verificacao'
            )
        SQL);

        $this->addSql(<<<'SQL'
            SET @rename_sql = IF(
                @col_exists > 0,
                'ALTER TABLE radar_medidor RENAME COLUMN local_verificacao TO logradouro',
                'SELECT 1'
            )
        SQL);

        $this->addSql('PREPARE stmt FROM @rename_sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        // Reverte: logradouro → local_verificacao (apenas se logradouro existir)
        $this->addSql(<<<'SQL'
            SET @col_exists = (
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'radar_medidor'
                  AND COLUMN_NAME  = 'logradouro'
            )
        SQL);

        $this->addSql(<<<'SQL'
            SET @rename_sql = IF(
                @col_exists > 0,
                'ALTER TABLE radar_medidor RENAME COLUMN logradouro TO local_verificacao',
                'SELECT 1'
            )
        SQL);

        $this->addSql('PREPARE stmt FROM @rename_sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }
}
