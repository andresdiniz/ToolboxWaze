<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Torna inserted_by nullable em radar_waze_link.
 *
 * Estratégia robusta: consulta o INFORMATION_SCHEMA para achar o nome
 * real da FK em runtime — evita erro 1091 (FK name incorreto) e 1005 (FK malformada).
 * Também desativa isTransactional() pois DDL no MySQL causa implicit commit.
 */
final class Version20260523_InsertedByNullable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Torna inserted_by nullable em radar_waze_link';
    }

    public function isTransactional(): bool
    {
        return false; // DDL no MySQL faz implicit commit — não pode estar em transação
    }

    public function up(Schema $schema): void
    {
        $db     = $this->connection;
        $dbName = $db->getDatabase();

        // Descobre o nome real da FK que aponta inserted_by -> user
        $fkName = $db->fetchOne(
            "SELECT CONSTRAINT_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA    = ?
               AND TABLE_NAME      = 'radar_waze_link'
               AND COLUMN_NAME     = 'inserted_by'
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1",
            [$dbName]
        );

        if ($fkName) {
            $db->executeStatement("ALTER TABLE radar_waze_link DROP FOREIGN KEY `{$fkName}`");
        }

        // Modifica a coluna para nullable
        $db->executeStatement(
            'ALTER TABLE radar_waze_link MODIFY inserted_by INT UNSIGNED NULL DEFAULT NULL'
        );

        // Recria a FK com ON DELETE SET NULL
        // Descobre o nome da coluna PK da tabela user
        $userPk = $db->fetchOne(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME   = 'user'
               AND CONSTRAINT_NAME = 'PRIMARY'
             LIMIT 1",
            [$dbName]
        ) ?: 'id';

        // Recria usando o mesmo nome original (ou gera novo se não existia)
        $newFkName = $fkName ?: 'fk_radar_waze_link_inserted_by';
        $db->executeStatement(
            "ALTER TABLE radar_waze_link
                ADD CONSTRAINT `{$newFkName}`
                FOREIGN KEY (inserted_by)
                REFERENCES `user` (`{$userPk}`)
                ON DELETE SET NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $db     = $this->connection;
        $dbName = $db->getDatabase();

        $fkName = $db->fetchOne(
            "SELECT CONSTRAINT_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA    = ?
               AND TABLE_NAME      = 'radar_waze_link'
               AND COLUMN_NAME     = 'inserted_by'
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1",
            [$dbName]
        );

        if ($fkName) {
            $db->executeStatement("ALTER TABLE radar_waze_link DROP FOREIGN KEY `{$fkName}`");
        }

        $db->executeStatement(
            'ALTER TABLE radar_waze_link MODIFY inserted_by INT UNSIGNED NOT NULL'
        );

        $userPk = $db->fetchOne(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME   = 'user'
               AND CONSTRAINT_NAME = 'PRIMARY'
             LIMIT 1",
            [$dbName]
        ) ?: 'id';

        $newFkName = $fkName ?: 'fk_radar_waze_link_inserted_by';
        $db->executeStatement(
            "ALTER TABLE radar_waze_link
                ADD CONSTRAINT `{$newFkName}`
                FOREIGN KEY (inserted_by)
                REFERENCES `user` (`{$userPk}`)"
        );
    }
}
