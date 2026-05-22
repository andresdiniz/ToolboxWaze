<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona o campo calculado data_verificacao_efetiva na tabela radar_medidor.
 *
 * Regra de preenchimento (replicada no PHP dos handlers):
 *   1. data_ultima_verificacao preenchida  → usa ela
 *   2. apenas data_validade preenchida     → data_validade - 1 ano
 *   3. ambas nulas                         → NULL
 *
 * Após criar a coluna, o UPDATE abaixo popula todos os registros
 * já existentes no banco sem necessidade de reimportar.
 */
final class Version20260522_radar_verificacao_efetiva extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADD COLUMN data_verificacao_efetiva (calculado na importação) + índice';
    }

    public function up(Schema $schema): void
    {
        // 1. Cria a coluna
        $this->addSql(
            'ALTER TABLE radar_medidor
             ADD COLUMN data_verificacao_efetiva VARCHAR(20) NULL
             AFTER data_validade'
        );

        // 2. Cria o índice para acelerar filtros por data
        $this->addSql(
            'CREATE INDEX idx_radar_data_verificacao_efetiva
             ON radar_medidor (data_verificacao_efetiva)'
        );

        // 3. Popula registros existentes com a mesma lógica do PHP:
        //    - Se data_ultima_verificacao não é nula/vazia → usa ela
        //    - Senão, se data_validade não é nula/vazia → validade - 1 ano
        //    - Senão → NULL
        $this->addSql("
            UPDATE radar_medidor
            SET data_verificacao_efetiva = CASE
                WHEN data_ultima_verificacao IS NOT NULL
                 AND data_ultima_verificacao <> ''
                    THEN data_ultima_verificacao
                WHEN data_validade IS NOT NULL
                 AND data_validade <> ''
                    THEN DATE_FORMAT(
                            DATE_SUB(
                                STR_TO_DATE(data_validade, '%d/%m/%Y'),
                                INTERVAL 1 YEAR
                            ),
                            '%d/%m/%Y'
                         )
                ELSE NULL
            END
            WHERE data_verificacao_efetiva IS NULL
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_radar_data_verificacao_efetiva ON radar_medidor');
        $this->addSql('ALTER TABLE radar_medidor DROP COLUMN data_verificacao_efetiva');
    }
}
