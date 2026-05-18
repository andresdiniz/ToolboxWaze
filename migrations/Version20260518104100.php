<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Altera a coluna permanent_hazard_id de INT para VARCHAR(60)
 * para suportar o ID completo do place no Waze (ex: "207160888.2071412273.41231195").
 */
final class Version20260518104100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Altera permanent_hazard_id para VARCHAR(60) em posto_waze_link e posto_waze_link_log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE posto_waze_link
             MODIFY COLUMN permanent_hazard_id VARCHAR(60) NOT NULL'
        );

        // A tabela de log armazena valor_anterior e valor_novo como TEXT,
        // portanto não precisa de ALTER. Mas se existir coluna separada, ajuste aqui.
    }

    public function down(Schema $schema): void
    {
        // Trunca para BIGINT — dados com pontos serão perdidos no rollback
        $this->addSql(
            'ALTER TABLE posto_waze_link
             MODIFY COLUMN permanent_hazard_id BIGINT NOT NULL'
        );
    }
}
