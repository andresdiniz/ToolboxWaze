<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Coluna gerada + índice para eliminar STR_TO_DATE() em runtime.
 *
 * O campo data_validade é armazenado como VARCHAR 'dd/mm/yyyy'.
 * Calcular STR_TO_DATE() em cada linha de cada request custa 77-127 ms.
 * Uma coluna STORED persiste o valor convertido em disco e permite
 * criar um índice B-tree normal nela.
 *
 * MariaDB 10.2+ / MySQL 5.7+ suportam colunas geradas STORED.
 */
final class Version20260726_ValidadeDate extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coluna gerada data_validade_date DATE STORED + índice para filtros de validade';
    }

    public function up(Schema $schema): void
    {
        // Coluna gerada persistida — calculada 1x no INSERT/UPDATE, nunca no SELECT
        $this->addSql(
            "ALTER TABLE radar_medidor
             ADD COLUMN data_validade_date DATE
                 GENERATED ALWAYS AS (STR_TO_DATE(data_validade, '%d/%m/%Y')) STORED
             AFTER data_validade"
        );

        // Índice para ORDER BY e filtros WHERE data_validade_date BETWEEN ? AND ?
        $this->addSql(
            'CREATE INDEX idx_radar_validade_date ON radar_medidor (data_validade_date)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_radar_validade_date ON radar_medidor');
        $this->addSql('ALTER TABLE radar_medidor DROP COLUMN data_validade_date');
    }
}
