<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Coluna "tipo" (UPA, UBS, Hospital...) de estabelecimento_saude, usada no filtro principal do WME.
 * Se o importador CNES 2.2 já criou a coluna, esta migration é ignorada (nada a fazer).
 */
final class Version20261106000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona estabelecimento_saude.tipo e o índice (uf, tipo), se ainda não existirem.';
    }

    public function up(Schema $schema): void
    {
        $tabela = $schema->getTable('estabelecimento_saude');

        $this->skipIf($tabela->hasColumn('tipo'), 'A coluna tipo já existe (criada pelo importador).');

        $this->addSql('ALTER TABLE estabelecimento_saude ADD tipo VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_es_uf_tipo ON estabelecimento_saude (uf, tipo)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_es_uf_tipo ON estabelecimento_saude');
        $this->addSql('ALTER TABLE estabelecimento_saude DROP tipo');
    }
}
