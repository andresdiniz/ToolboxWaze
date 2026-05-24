<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524_radar_manual_serie extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona numero_serie, fonte e marca em radar_manual';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE radar_manual
             ADD COLUMN numero_serie VARCHAR(100) NULL DEFAULT NULL AFTER tipo_medidor,
             ADD COLUMN marca        VARCHAR(100) NULL DEFAULT NULL AFTER numero_serie,
             ADD COLUMN fonte        VARCHAR(1000) NULL DEFAULT NULL AFTER observacoes"
        );

        $this->addSql(
            'CREATE INDEX idx_radar_manual_serie ON radar_manual (numero_serie)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_radar_manual_serie ON radar_manual');
        $this->addSql(
            'ALTER TABLE radar_manual
             DROP COLUMN IF EXISTS numero_serie,
             DROP COLUMN IF EXISTS marca,
             DROP COLUMN IF EXISTS fonte'
        );
    }
}
