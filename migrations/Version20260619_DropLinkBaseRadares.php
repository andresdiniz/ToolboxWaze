<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove a coluna link_base_radares da tabela brazilian_state.
 *
 * Essa coluna era usada para apontar para a aba de medidores na planilha
 * Google Sheets. Como a importação passou a usar diretamente a API JSON
 * do RBMLQ/INMETRO, a coluna não é mais necessária.
 *
 * Para rodar:
 *   php bin/console doctrine:migrations:migrate
 *
 * Para reverter (cria a coluna de volta como nullable):
 *   php bin/console doctrine:migrations:migrate prev
 */
final class Version20260619_DropLinkBaseRadares extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove link_base_radares de brazilian_state — importação usa JSON RBMLQ diretamente';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brazilian_state DROP COLUMN link_base_radares');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brazilian_state ADD COLUMN link_base_radares VARCHAR(500) NULL DEFAULT NULL AFTER region');
    }
}
