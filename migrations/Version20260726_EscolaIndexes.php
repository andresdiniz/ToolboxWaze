<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Índices para escola_inep — resolve queries lentas do profiler (token 095f49).
 *
 * idx_escola_sort (uf, escola)           → ORDER BY escola + filtro uf=?
 * idx_escola_escola (escola)             → ORDER BY escola sem filtro uf
 * idx_escola_dep (dependencia_adm)       → DISTINCT dependencia_administrativa
 * idx_escola_loc (localizacao)           → DISTINCT localizacao
 *
 * Todos com IF NOT EXISTS — idempotentes.
 */
final class Version20260726_EscolaIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Índices de performance para escola_inep (sort, distinct)';
    }

    public function up(Schema $schema): void
    {
        // Cobre WHERE uf = ? ORDER BY escola (filtro por UF)
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_escola_sort
             ON escola_inep (uf, escola)'
        );

        // Cobre ORDER BY escola sem filtro (listagem padrão)
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_escola_nome
             ON escola_inep (escola)'
        );

        // Cobre DISTINCT dependencia_administrativa
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_escola_dep
             ON escola_inep (dependencia_administrativa)'
        );

        // Cobre DISTINCT localizacao
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_escola_loc
             ON escola_inep (localizacao)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_escola_sort ON escola_inep');
        $this->addSql('DROP INDEX IF EXISTS idx_escola_nome ON escola_inep');
        $this->addSql('DROP INDEX IF EXISTS idx_escola_dep  ON escola_inep');
        $this->addSql('DROP INDEX IF EXISTS idx_escola_loc  ON escola_inep');
    }
}
