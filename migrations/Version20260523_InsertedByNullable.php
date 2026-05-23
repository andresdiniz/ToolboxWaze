<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Torna inserted_by nullable em radar_waze_link.
 * Permite inserções automáticas via CLI sem vínculo a um User.
 */
final class Version20260523_InsertedByNullable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Torna inserted_by nullable em radar_waze_link (importações automáticas via CLI)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE radar_waze_link MODIFY inserted_by INT UNSIGNED NULL DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        // Atenção: registros com inserted_by = NULL serão bloqueados pelo rollback.
        // Limpe-os antes se necessário.
        $this->addSql(
            'ALTER TABLE radar_waze_link MODIFY inserted_by INT UNSIGNED NOT NULL'
        );
    }
}
