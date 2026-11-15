<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona atualizado_em e segmento_id às solicitações.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE solicitacoes ADD atualizado_em DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE solicitacoes SET atualizado_em = criado_em');
        $this->addSql('ALTER TABLE solicitacoes ADD segmento_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_SOLICITACOES_ATUALIZADO_EM ON solicitacoes (atualizado_em)');
        $this->addSql('CREATE INDEX IDX_SOLICITACOES_SEGMENTO_ID ON solicitacoes (segmento_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_SOLICITACOES_SEGMENTO_ID ON solicitacoes');
        $this->addSql('DROP INDEX IDX_SOLICITACOES_ATUALIZADO_EM ON solicitacoes');
        $this->addSql('ALTER TABLE solicitacoes DROP segmento_id');
        $this->addSql('ALTER TABLE solicitacoes DROP atualizado_em');
    }
}