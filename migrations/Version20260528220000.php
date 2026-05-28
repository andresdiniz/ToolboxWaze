<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona a coluna merged_into_id na tabela radar_medidor.
 * Corrige o erro: Unknown column 'rm.merged_into_id' in 'WHERE'
 */
final class Version20260528220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'radar_medidor: adiciona merged_into_id (FK para merge de radares duplicados)';
    }

    public function up(Schema $schema): void
    {
        // Adiciona a coluna apenas se não existir (seguro rodar mesmo que já exista)
        $this->addSql('
            ALTER TABLE radar_medidor
            ADD COLUMN IF NOT EXISTS merged_into_id BIGINT UNSIGNED DEFAULT NULL,
            ADD INDEX IF NOT EXISTS idx_rm_merged_into (merged_into_id)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE radar_medidor DROP INDEX idx_rm_merged_into, DROP COLUMN merged_into_id');
    }
}
