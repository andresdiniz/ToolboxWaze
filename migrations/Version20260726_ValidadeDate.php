<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Coluna gerada data_validade_date + índice — idempotente.
 *
 * Usa information_schema para verificar se a coluna/índice já existem
 * antes de criar — não falha se a migration foi parcialmente aplicada.
 *
 * Separado em dois addSql() porque Doctrine Migrations executa cada
 * chamada como statement independente (sem suporte a multi-statement
 * com PREPARE/EXECUTE nativo).
 */
final class Version20260726_ValidadeDate extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coluna gerada data_validade_date DATE STORED + índice (idempotente)';
    }

    public function up(Schema $schema): void
    {
        // 1. Adiciona coluna apenas se não existir
        $this->addSql("
            ALTER TABLE radar_medidor
            ADD COLUMN IF NOT EXISTS data_validade_date DATE
                GENERATED ALWAYS AS (STR_TO_DATE(data_validade, '%d/%m/%Y')) STORED
            AFTER data_validade
        ");

        // 2. Cria índice apenas se não existir
        $this->addSql("
            CREATE INDEX IF NOT EXISTS idx_radar_validade_date
            ON radar_medidor (data_validade_date)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_radar_validade_date ON radar_medidor');
        $this->addSql('ALTER TABLE radar_medidor DROP COLUMN IF EXISTS data_validade_date');
    }
}
