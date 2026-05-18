<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518_improvements extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona coluna status a fuel_reseller_raw e índices de performance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE fuel_reseller_raw ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'ativo'"
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_posto_waze_link_posto_id ON posto_waze_link (posto_id)'
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_posto_waze_link_hazard_id ON posto_waze_link (permanent_hazard_id)'
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_fuel_uf_status ON fuel_reseller_raw (uf, status)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_posto_waze_link_posto_id');
        $this->addSql('DROP INDEX IF EXISTS idx_posto_waze_link_hazard_id');
        $this->addSql('DROP INDEX IF EXISTS idx_fuel_uf_status');
        $this->addSql('ALTER TABLE fuel_reseller_raw DROP COLUMN IF EXISTS status');
    }
}
