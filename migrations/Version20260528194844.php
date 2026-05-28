<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260528194844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE radar_manual RENAME INDEX fk_2b1bb1c5f42f4a03 TO IDX_2B1BB1C5F42F4A03');
        $this->addSql('DROP INDEX idx_radar_uf ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_criado_por ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_data_validade ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_proprietario_nome ON radar_medidor');
        $this->addSql('DROP INDEX uq_radar_row_hash ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_origem ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_ultimo_resultado ON radar_medidor');
        $this->addSql('ALTER TABLE radar_medidor ADD uf VARCHAR(2) NOT NULL, ADD logradouro VARCHAR(255) DEFAULT NULL, ADD cep VARCHAR(20) DEFAULT NULL, ADD nome_empresa VARCHAR(255) DEFAULT NULL, ADD cnpj_empresa VARCHAR(20) DEFAULT NULL, ADD modelo_medidor VARCHAR(100) DEFAULT NULL, ADD numero_serie VARCHAR(100) DEFAULT NULL, ADD situacao VARCHAR(20) DEFAULT NULL, ADD data_verificacao VARCHAR(20) DEFAULT NULL, ADD data_lacre VARCHAR(20) DEFAULT NULL, ADD lacre VARCHAR(100) DEFAULT NULL, ADD numero_certificado VARCHAR(100) DEFAULT NULL, ADD orgao_verificador VARCHAR(100) DEFAULT NULL, ADD latitude NUMERIC(10, 7) DEFAULT NULL, ADD longitude NUMERIC(10, 7) DEFAULT NULL, ADD inserted_by VARCHAR(100) DEFAULT NULL, DROP local_verificacao, DROP proprietario_nome, DROP proprietario_municipio, DROP proprietario_estado, DROP faixas_json, DROP historico_json, DROP origem, DROP criado_por, DROP merged_into_id, CHANGE sigla_uf sigla_uf VARCHAR(2) DEFAULT NULL, CHANGE municipio municipio VARCHAR(100) DEFAULT NULL, CHANGE tipo_medidor tipo_medidor VARCHAR(100) DEFAULT NULL, CHANGE row_hash row_hash VARCHAR(64) NOT NULL, CHANGE link_waze link_waze VARCHAR(500) DEFAULT NULL, CHANGE estado marca_medidor VARCHAR(100) DEFAULT NULL, CHANGE ultimo_resultado capacidade VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_radar_cnpj ON radar_medidor (cnpj_empresa)');
        $this->addSql('CREATE INDEX idx_radar_num_serie ON radar_medidor (numero_serie)');
        $this->addSql('CREATE INDEX idx_radar_identity ON radar_medidor (identity_hash)');
        $this->addSql('CREATE INDEX idx_radar_uf ON radar_medidor (uf)');
        $this->addSql('DROP INDEX idx_merge_survivor ON radar_merge_log');
        $this->addSql('DROP INDEX idx_merge_absorbed ON radar_merge_log');
        $this->addSql('ALTER TABLE radar_waze_link CHANGE inserted_by inserted_by INT NOT NULL');
        $this->addSql('ALTER TABLE radar_waze_link ADD CONSTRAINT FK_CE3EA19FC935C3D1 FOREIGN KEY (inserted_by) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE solicitacao_comentarios DROP FOREIGN KEY `FK_F37F63A914D45BBE`');
        $this->addSql('ALTER TABLE solicitacao_comentarios ADD CONSTRAINT FK_F37F63A914D45BBE FOREIGN KEY (autor_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user CHANGE api_token_generated_at api_token_generated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE user RENAME INDEX uniq_8d93d649fb2d4546 TO UNIQ_8D93D6497BA2F5EB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE radar_manual RENAME INDEX idx_2b1bb1c5f42f4a03 TO FK_2B1BB1C5F42F4A03');
        $this->addSql('DROP INDEX idx_radar_uf ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_cnpj ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_num_serie ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_identity ON radar_medidor');
        $this->addSql('ALTER TABLE radar_medidor ADD estado VARCHAR(100) DEFAULT NULL, ADD local_verificacao VARCHAR(255) DEFAULT NULL, ADD proprietario_nome VARCHAR(255) DEFAULT NULL, ADD proprietario_municipio VARCHAR(150) DEFAULT NULL, ADD proprietario_estado VARCHAR(2) DEFAULT NULL, ADD faixas_json JSON DEFAULT NULL, ADD historico_json JSON DEFAULT NULL, ADD origem ENUM(\'inmetro\', \'manual\') DEFAULT \'inmetro\' NOT NULL COMMENT \'Fonte do registro: importado do INMETRO ou criado manualmente\', ADD criado_por INT UNSIGNED DEFAULT NULL COMMENT \'ID do usuário que criou o registro manualmente\', ADD merged_into_id BIGINT UNSIGNED DEFAULT NULL, DROP uf, DROP logradouro, DROP cep, DROP nome_empresa, DROP cnpj_empresa, DROP marca_medidor, DROP modelo_medidor, DROP numero_serie, DROP situacao, DROP data_verificacao, DROP data_lacre, DROP lacre, DROP numero_certificado, DROP orgao_verificador, DROP latitude, DROP longitude, DROP inserted_by, CHANGE sigla_uf sigla_uf VARCHAR(2) NOT NULL, CHANGE municipio municipio VARCHAR(150) DEFAULT NULL, CHANGE tipo_medidor tipo_medidor VARCHAR(50) DEFAULT NULL, CHANGE row_hash row_hash VARCHAR(64) DEFAULT NULL COMMENT \'SHA-256 do JSON INMETRO; NULL enquanto ainda não aparecer na base oficial\', CHANGE link_waze link_waze VARCHAR(500) DEFAULT NULL COMMENT \'URL do radar no Waze (aba REFERENCIA.UF da planilha)\', CHANGE capacidade ultimo_resultado VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_radar_criado_por ON radar_medidor (criado_por)');
        $this->addSql('CREATE INDEX idx_radar_data_validade ON radar_medidor (data_validade)');
        $this->addSql('CREATE INDEX idx_radar_proprietario_nome ON radar_medidor (proprietario_nome)');
        $this->addSql('CREATE UNIQUE INDEX uq_radar_row_hash ON radar_medidor (row_hash)');
        $this->addSql('CREATE INDEX idx_radar_origem ON radar_medidor (origem)');
        $this->addSql('CREATE INDEX idx_radar_ultimo_resultado ON radar_medidor (ultimo_resultado)');
        $this->addSql('CREATE INDEX idx_radar_uf ON radar_medidor (sigla_uf)');
        $this->addSql('CREATE INDEX idx_merge_survivor ON radar_merge_log (survivor_id)');
        $this->addSql('CREATE INDEX idx_merge_absorbed ON radar_merge_log (absorbed_id)');
        $this->addSql('ALTER TABLE radar_waze_link DROP FOREIGN KEY FK_CE3EA19FC935C3D1');
        $this->addSql('ALTER TABLE radar_waze_link CHANGE inserted_by inserted_by INT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE solicitacao_comentarios DROP FOREIGN KEY FK_F37F63A914D45BBE');
        $this->addSql('ALTER TABLE solicitacao_comentarios ADD CONSTRAINT `FK_F37F63A914D45BBE` FOREIGN KEY (autor_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE `user` CHANGE api_token_generated_at api_token_generated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE `user` RENAME INDEX uniq_8d93d6497ba2f5eb TO UNIQ_8D93D649FB2D4546');
    }
}
