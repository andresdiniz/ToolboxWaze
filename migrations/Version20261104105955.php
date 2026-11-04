<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104105955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE escola_inep ADD waze_snapshot JSON DEFAULT NULL, ADD waze_snapshot_em DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX UNIQ_ESTABELECIMENTO_CNES ON estabelecimento_saude');
        $this->addSql('ALTER TABLE estabelecimento_saude ADD waze_snapshot JSON DEFAULT NULL, DROP dados_originais, DROP correcoes, DROP importado_em, DROP editado_por, DROP versao, CHANGE editado_em waze_snapshot_em DATETIME DEFAULT NULL');
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
        $this->addSql('ALTER TABLE escola_inep DROP waze_snapshot, DROP waze_snapshot_em');
        $this->addSql('ALTER TABLE estabelecimento_saude ADD dados_originais JSON NOT NULL, ADD correcoes JSON DEFAULT \'json_object()\' NOT NULL, ADD importado_em DATETIME NOT NULL, ADD editado_por VARCHAR(180) DEFAULT NULL, ADD versao INT UNSIGNED DEFAULT 1 NOT NULL, DROP waze_snapshot, CHANGE waze_snapshot_em editado_em DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ESTABELECIMENTO_CNES ON estabelecimento_saude (cnes)');
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
