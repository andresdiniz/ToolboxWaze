<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106105623 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX wdbi_nome_fantasia_7aa045f1b440 ON estabelecimento_saude');
        $this->addSql('DROP INDEX wdbi_uf_ativo_latitude_longitude_bc4fbb31980c ON estabelecimento_saude');
        $this->addSql('ALTER TABLE estabelecimento_saude ADD tipo VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_es_uf_tipo ON estabelecimento_saude (uf, tipo)');
        $this->addSql('ALTER TABLE radar_faixa CHANGE radar_medidor_id radar_medidor_id INT NOT NULL');
        $this->addSql('ALTER TABLE radar_historico CHANGE radar_medidor_id radar_medidor_id INT NOT NULL');
        $this->addSql('DROP INDEX idx_radar_cnpj ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_data_verificacao_efetiva ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_identity ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_municipio ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_num_serie ON radar_medidor');
        $this->addSql('DROP INDEX idx_radar_uf ON radar_medidor');
        $this->addSql('ALTER TABLE radar_medidor CHANGE id id INT AUTO_INCREMENT NOT NULL');
        $this->addSql('ALTER TABLE radar_waze_link CHANGE radar_medidor_id radar_medidor_id INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_es_uf_tipo ON estabelecimento_saude');
        $this->addSql('ALTER TABLE estabelecimento_saude DROP tipo');
        $this->addSql('CREATE INDEX wdbi_nome_fantasia_7aa045f1b440 ON estabelecimento_saude (nome_fantasia)');
        $this->addSql('CREATE INDEX wdbi_uf_ativo_latitude_longitude_bc4fbb31980c ON estabelecimento_saude (uf, ativo, latitude, longitude)');
        $this->addSql('ALTER TABLE radar_faixa CHANGE radar_medidor_id radar_medidor_id BIGINT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE radar_historico CHANGE radar_medidor_id radar_medidor_id BIGINT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE radar_medidor CHANGE id id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL');
        $this->addSql('CREATE INDEX idx_radar_cnpj ON radar_medidor (cnpj_empresa)');
        $this->addSql('CREATE INDEX idx_radar_data_verificacao_efetiva ON radar_medidor (data_verificacao_efetiva)');
        $this->addSql('CREATE INDEX idx_radar_identity ON radar_medidor (identity_hash)');
        $this->addSql('CREATE INDEX idx_radar_municipio ON radar_medidor (municipio)');
        $this->addSql('CREATE INDEX idx_radar_num_serie ON radar_medidor (numero_serie)');
        $this->addSql('CREATE INDEX idx_radar_uf ON radar_medidor (uf)');
        $this->addSql('ALTER TABLE radar_waze_link CHANGE radar_medidor_id radar_medidor_id BIGINT UNSIGNED NOT NULL');
    }
}
