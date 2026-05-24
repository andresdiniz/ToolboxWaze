<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524_BrazilianStateLinkReferencia extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona link_referencia_radares em brazilian_state (URL da aba REFERENCIA.UF para importar links Waze)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE brazilian_state
             ADD COLUMN link_referencia_radares VARCHAR(500) DEFAULT NULL
             COMMENT 'URL CSV da aba REFERENCIA.UF — usada para importar links Waze pelo Nº de Série'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brazilian_state DROP COLUMN link_referencia_radares');
    }
}
