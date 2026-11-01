<?php

declare(strict_types=1);

namespace Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona status e reservado_para na tabela escola_inep';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escola_inep ADD status VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE escola_inep ADD reservado_para VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ei_status ON escola_inep (status)');
        $this->addSql('CREATE INDEX idx_ei_reservado_para ON escola_inep (reservado_para)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ei_reservado_para ON escola_inep');
        $this->addSql('DROP INDEX idx_ei_status ON escola_inep');
        $this->addSql('ALTER TABLE escola_inep DROP reservado_para');
        $this->addSql('ALTER TABLE escola_inep DROP status');
    }
}
