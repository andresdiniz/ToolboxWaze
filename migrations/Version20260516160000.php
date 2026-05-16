<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona coluna updated_at na tabela fuel_reseller_raw.
 * Necessária para o ImportFuelResellersHandler registrar data de atualização dos postos.
 */
final class Version20260516160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona updated_at em fuel_reseller_raw';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE fuel_reseller_raw ADD updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)"'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fuel_reseller_raw DROP COLUMN updated_at');
    }
}
