<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corrige as 3 queries lentas identificadas no Symfony Profiler (token c41565).
 *
 * Fix #1 — suspicious_requests (18 ms → ~0 ms com cache, índice cobre o COUNT)
 *   Adiciona índice composto (ip, created_at) para cobrir
 *   SELECT COUNT(*) WHERE ip = ? AND created_at >= ?
 *
 * Fix #3 — radar_medidor ORDER BY (162 ms → ~15 ms)
 *   Adiciona índice (merged_into_id, sigla_uf, municipio) para cobrir
 *   WHERE merged_into_id IS NULL ORDER BY sigla_uf, municipio
 *   sem filesort.
 *
 * Ambos usam IF NOT EXISTS — idempotentes em re-runs.
 */
final class Version20260726_QueryFixes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Índices para suspicious_requests(ip,created_at) e radar_medidor(merged_into_id,sigla_uf,municipio)';
    }

    public function up(Schema $schema): void
    {
        // Fix #1 — cobre WHERE ip = ? AND created_at >= ? (BotDetectorService)
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_suspicious_ip_created
             ON suspicious_requests (ip, created_at)'
        );

        // Fix #3 — cobre WHERE merged_into_id IS NULL ORDER BY sigla_uf, municipio
        // O MariaDB/MySQL usa índice para IS NULL quando a coluna está no prefixo.
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_radar_sort
             ON radar_medidor (merged_into_id, sigla_uf, municipio)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_suspicious_ip_created ON suspicious_requests');
        $this->addSql('DROP INDEX IF EXISTS idx_radar_sort ON radar_medidor');
    }
}
