<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona suporte a radares cadastrados manualmente pelo usuário.
 *
 * Alterações:
 *   - radar_medidor.origem  ENUM('inmetro','manual')  DEFAULT 'inmetro'
 *   - radar_medidor.criado_por  INT UNSIGNED NULL FK user(id)
 *   - Remove a constraint UNIQUE em row_hash (radares manuais não têm hash INMETRO)
 *     e a recria como UNIQUE NULL-safe (NULL não conflita com outros NULLs no MySQL).
 */
final class Version20260524_radar_manual extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suporte a radares criados manualmente: colunas origem e criado_por';
    }

    public function up(Schema $schema): void
    {
        // 1. Adiciona coluna origem
        $this->addSql("
            ALTER TABLE radar_medidor
                ADD COLUMN origem ENUM('inmetro','manual') NOT NULL DEFAULT 'inmetro'
                    COMMENT 'Fonte do registro: importado do INMETRO ou criado manualmente'
                    AFTER updated_at
        ");

        // 2. Adiciona FK criado_por (NULL = importado automaticamente)
        $this->addSql("
            ALTER TABLE radar_medidor
                ADD COLUMN criado_por INT UNSIGNED NULL
                    COMMENT 'ID do usuário que criou o registro manualmente'
                    AFTER origem,
                ADD INDEX idx_radar_origem (origem),
                ADD INDEX idx_radar_criado_por (criado_por)
        ");

        // 3. Torna row_hash nullable para radares manuais (ainda antes do merge INMETRO)
        $this->addSql("
            ALTER TABLE radar_medidor
                MODIFY COLUMN row_hash VARCHAR(64) NULL
                    COMMENT 'SHA-256 do JSON INMETRO; NULL enquanto ainda não aparecer na base oficial'
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE radar_medidor DROP INDEX idx_radar_origem, DROP INDEX idx_radar_criado_por');
        $this->addSql('ALTER TABLE radar_medidor DROP COLUMN origem, DROP COLUMN criado_por');
        $this->addSql('ALTER TABLE radar_medidor MODIFY COLUMN row_hash VARCHAR(64) NOT NULL');
    }
}
