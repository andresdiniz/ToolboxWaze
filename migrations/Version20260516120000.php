<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Transforma o índice idx_frr_row_hash em UNIQUE KEY.
 *
 * Isso permite usar INSERT ... ON DUPLICATE KEY UPDATE para fazer upsert
 * eficiente: novas linhas são inseridas, linhas existentes são atualizadas
 * SOMENTE se o row_hash mudar (ou seja, algum campo do CSV foi alterado).
 *
 * Se o row_hash for idêntico ao já gravado, o banco ignora a linha — zero I/O
 * de escrita para registros sem mudança.
 */
final class Version20260516120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Torna row_hash UNIQUE para suportar upsert eficiente (ON DUPLICATE KEY UPDATE)';
    }

    public function up(Schema $schema): void
    {
        // Remove o índice normal e recria como UNIQUE
        $this->addSql('ALTER TABLE fuel_reseller_raw DROP INDEX idx_frr_row_hash');
        $this->addSql('ALTER TABLE fuel_reseller_raw ADD UNIQUE KEY uq_frr_row_hash (row_hash)');

        // Adiciona coluna updated_at para rastrear quando o registro foi atualizado pela última vez
        $this->addSql('
            ALTER TABLE fuel_reseller_raw
                ADD COLUMN updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)" AFTER imported_at
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fuel_reseller_raw DROP INDEX uq_frr_row_hash');
        $this->addSql('ALTER TABLE fuel_reseller_raw ADD INDEX idx_frr_row_hash (row_hash)');
        $this->addSql('ALTER TABLE fuel_reseller_raw DROP COLUMN updated_at');
    }
}
