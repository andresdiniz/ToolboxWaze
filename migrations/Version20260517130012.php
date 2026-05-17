<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260517130012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE solicitacoes (id INT AUTO_INCREMENT NOT NULL, tipo VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, solicitante_nome VARCHAR(255) NOT NULL, solicitante_usuario VARCHAR(255) NOT NULL, solicitante_email VARCHAR(255) NOT NULL, estado VARCHAR(2) DEFAULT NULL, dados JSON DEFAULT NULL, arquivos JSON DEFAULT NULL, resolvida_em DATETIME DEFAULT NULL, nota_resolucao LONGTEXT DEFAULT NULL, criada_em DATETIME NOT NULL, atualizada_em DATETIME NOT NULL, resolvida_por_id INT DEFAULT NULL, INDEX IDX_1F03F044EC51598A (resolvida_por_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE solicitacao_responsaveis (solicitacao_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_CD4F514E774BE1CF (solicitacao_id), INDEX IDX_CD4F514EA76ED395 (user_id), PRIMARY KEY (solicitacao_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tipo_solicitacao_config (id INT AUTO_INCREMENT NOT NULL, tipo VARCHAR(64) NOT NULL, UNIQUE INDEX UNIQ_C52479E5702D1D47 (tipo), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tipo_solicitacao_responsaveis (tipo_solicitacao_config_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_C44D667FB96F1D0 (tipo_solicitacao_config_id), INDEX IDX_C44D667FA76ED395 (user_id), PRIMARY KEY (tipo_solicitacao_config_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE solicitacoes ADD CONSTRAINT FK_1F03F044EC51598A FOREIGN KEY (resolvida_por_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE solicitacao_responsaveis ADD CONSTRAINT FK_CD4F514E774BE1CF FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE solicitacao_responsaveis ADD CONSTRAINT FK_CD4F514EA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tipo_solicitacao_responsaveis ADD CONSTRAINT FK_C44D667FB96F1D0 FOREIGN KEY (tipo_solicitacao_config_id) REFERENCES tipo_solicitacao_config (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tipo_solicitacao_responsaveis ADD CONSTRAINT FK_C44D667FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE solicitacoes DROP FOREIGN KEY FK_1F03F044EC51598A');
        $this->addSql('ALTER TABLE solicitacao_responsaveis DROP FOREIGN KEY FK_CD4F514E774BE1CF');
        $this->addSql('ALTER TABLE solicitacao_responsaveis DROP FOREIGN KEY FK_CD4F514EA76ED395');
        $this->addSql('ALTER TABLE tipo_solicitacao_responsaveis DROP FOREIGN KEY FK_C44D667FB96F1D0');
        $this->addSql('ALTER TABLE tipo_solicitacao_responsaveis DROP FOREIGN KEY FK_C44D667FA76ED395');
        $this->addSql('DROP TABLE solicitacoes');
        $this->addSql('DROP TABLE solicitacao_responsaveis');
        $this->addSql('DROP TABLE tipo_solicitacao_config');
        $this->addSql('DROP TABLE tipo_solicitacao_responsaveis');
    }
}
