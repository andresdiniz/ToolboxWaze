<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona data_validade_iso (DATE) à tabela radar_medidor.
 * Popula convertendo data_validade (formato dd/mm/aaaa) para ISO yyyy-mm-dd.
 */
final class Version20260528230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADD COLUMN data_validade_iso DATE na radar_medidor + popula a partir de data_validade';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE radar_medidor
             ADD COLUMN data_validade_iso DATE NULL
             AFTER data_validade"
        );

        $this->addSql(
            "CREATE INDEX idx_radar_data_validade_iso ON radar_medidor (data_validade_iso)"
        );

        $this->addSql("
            UPDATE radar_medidor
            SET data_validade_iso = STR_TO_DATE(data_validade, '%d/%m/%Y')
            WHERE data_validade IS NOT NULL
              AND data_validade <> ''
              AND data_validade_iso IS NULL
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_radar_data_validade_iso ON radar_medidor');
        $this->addSql('ALTER TABLE radar_medidor DROP COLUMN data_validade_iso');
    }
}
