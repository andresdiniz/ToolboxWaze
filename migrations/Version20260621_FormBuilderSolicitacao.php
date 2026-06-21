<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Liga FormBuilder com Solicitacao:
 * - adiciona coluna formulario_id (FK nullable) em solicitacoes
 * - adiciona coluna dados_dinamicos (JSON nullable) em solicitacoes
 */
final class Version20260621_FormBuilderSolicitacao extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona formulario_id e dados_dinamicos à tabela solicitacoes';
    }

    public function up(Schema $schema): void
    {
        // Verifica se as colunas já existem antes de adicionar
        $this->addSql(<<<'SQL'
            ALTER TABLE solicitacoes
                ADD COLUMN IF NOT EXISTS formulario_id INT DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS dados_dinamicos JSON DEFAULT NULL
            SQL
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE solicitacoes
                ADD CONSTRAINT IF NOT EXISTS FK_solicitacoes_form_builder
                FOREIGN KEY (formulario_id)
                REFERENCES form_builder(id)
                ON DELETE SET NULL
            SQL
        );

        $this->addSql(
            'CREATE INDEX IF NOT EXISTS IDX_solicitacoes_formulario ON solicitacoes (formulario_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE solicitacoes DROP FOREIGN KEY IF EXISTS FK_solicitacoes_form_builder');
        $this->addSql('DROP INDEX IF EXISTS IDX_solicitacoes_formulario ON solicitacoes');
        $this->addSql('ALTER TABLE solicitacoes DROP COLUMN IF EXISTS formulario_id');
        $this->addSql('ALTER TABLE solicitacoes DROP COLUMN IF EXISTS dados_dinamicos');
    }
}
