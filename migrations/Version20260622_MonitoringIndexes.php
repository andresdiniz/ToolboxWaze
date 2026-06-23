<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #1 – Índices compostos em monitoring_event para queries do dashboard.
 */
final class Version20260622_MonitoringIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona índices compostos em monitoring_event (type+created_at, session_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE INDEX idx_monitoring_type_created ON monitoring_event (type, created_at)'
        );
        $this->addSql(
            'CREATE INDEX idx_monitoring_session ON monitoring_event (session_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_monitoring_type_created ON monitoring_event');
        $this->addSql('DROP INDEX idx_monitoring_session ON monitoring_event');
    }
}
