<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Torna inserted_by nullable em radar_waze_link.
 *
 * A coluna inserted_by não possui FOREIGN KEY no banco — apenas MODIFY basta.
 */
final class Version20260523_InsertedByNullable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Torna inserted_by nullable em radar_waze_link';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE radar_waze_link MODIFY inserted_by INT UNSIGNED NULL DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE radar_waze_link MODIFY inserted_by INT UNSIGNED NOT NULL'
        );
    }
}
