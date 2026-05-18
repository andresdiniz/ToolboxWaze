<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona a coluna `observacao` (TEXT, nullable) à tabela `radar_waze_link_log`.
 *
 * Motivação: o campo foi introduzido na entidade RadarWazeLinkLog para que o
 * operador possa registrar uma nota livre no momento da alteração do link,
 * independentemente do motivo gravado na própria tabela radar_waze_link.
 *
 * Rollback (down): remove a coluna sem perda de dados críticos, pois é nullable.
 */
final class Version20260518092100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona coluna observacao (TEXT nullable) em radar_waze_link_log';
    }

    public function up(Schema $schema): void
    {
        // Adiciona após a coluna valor_novo para manter ordem lógica no schema
        $this->addSql(<<<'SQL'
            ALTER TABLE radar_waze_link_log
                ADD COLUMN observacao LONGTEXT DEFAULT NULL
                    COMMENT 'Nota livre do operador no momento da alteração'
                    AFTER valor_novo
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE radar_waze_link_log
                DROP COLUMN observacao
        SQL);
    }
}
