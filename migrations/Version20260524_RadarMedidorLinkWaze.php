<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona link_waze em radar_medidor.
 * Esse campo armazena a URL do radar no Waze, importada da aba REFERENCIA.UF
 * da planilha Google Sheets, cruzando pelo numero_serie (Nº de série).
 */
final class Version20260524_RadarMedidorLinkWaze extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona link_waze (VARCHAR 500, nullable) em radar_medidor';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        // Só adiciona se a coluna ainda não existir (safe para re-run)
        $this->addSql(
            "ALTER TABLE radar_medidor
             ADD COLUMN IF NOT EXISTS link_waze VARCHAR(500) NULL DEFAULT NULL
             COMMENT 'URL do radar no Waze (aba REFERENCIA.UF da planilha)'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE radar_medidor DROP COLUMN IF EXISTS link_waze');
    }
}
