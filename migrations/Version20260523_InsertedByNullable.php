<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Torna inserted_by nullable em radar_waze_link.
 * Permite inserções automáticas via CLI sem vínculo a um User.
 *
 * FIX: MySQL não permite MODIFY COLUMN enquanto a FK estiver ativa.
 * Solução: DROP FK → MODIFY → ADD FK novamente.
 */
final class Version20260523_InsertedByNullable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Torna inserted_by nullable em radar_waze_link (importações automáticas via CLI)';
    }

    public function up(Schema $schema): void
    {
        // 1. Remove a FK que bloqueia o MODIFY
        $this->addSql('ALTER TABLE radar_waze_link DROP FOREIGN KEY FK_CE3EA19FC935C3D1');

        // 2. Altera a coluna para nullable
        $this->addSql('ALTER TABLE radar_waze_link MODIFY inserted_by INT UNSIGNED NULL DEFAULT NULL');

        // 3. Recria a FK (ON DELETE SET NULL para não perder o link se o user for removido)
        $this->addSql('
            ALTER TABLE radar_waze_link
                ADD CONSTRAINT FK_CE3EA19FC935C3D1
                FOREIGN KEY (inserted_by) REFERENCES user (id)
                ON DELETE SET NULL
        ');
    }

    public function down(Schema $schema): void
    {
        // Remove registros com inserted_by = NULL antes do rollback, se necessário.
        $this->addSql('ALTER TABLE radar_waze_link DROP FOREIGN KEY FK_CE3EA19FC935C3D1');
        $this->addSql('ALTER TABLE radar_waze_link MODIFY inserted_by INT UNSIGNED NOT NULL');
        $this->addSql('
            ALTER TABLE radar_waze_link
                ADD CONSTRAINT FK_CE3EA19FC935C3D1
                FOREIGN KEY (inserted_by) REFERENCES user (id)
        ');
    }
}
