<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria estabelecimentos CNES com fonte original e correcoes separadas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE estabelecimento_saude (
            id INT AUTO_INCREMENT NOT NULL,
            cnes VARCHAR(7) NOT NULL,
            dados_originais JSON NOT NULL,
            correcoes JSON NOT NULL,
            importado_em DATETIME NOT NULL,
            editado_em DATETIME DEFAULT NULL,
            editado_por INT DEFAULT NULL,
            versao INT NOT NULL DEFAULT 1,
            UNIQUE INDEX uniq_saude_cnes (cnes),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Remover esta tabela apagaria dados importados e correcoes manuais.'
        );
    }
}
