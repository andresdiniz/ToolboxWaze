<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102015324 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE escola_inep ADD reservado_em DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ei_uf_status_reservado_em ON escola_inep (uf, status, reservado_em)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_ei_uf_status_reservado_em ON escola_inep');
        $this->addSql('ALTER TABLE escola_inep DROP reservado_em');
    }
}
