<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recria radar_medidor com a estrutura real do JSON RBMLQ e
 * cria as tabelas radar_faixa e radar_historico.
 *
 * DROP + CREATE porque a estrutura de colunas mudou completamente.
 * Se já tiver dados em radar_medidor, apague antes ou ajuste manualmente.
 */
final class Version20260516140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recria radar_medidor (JSON real RBMLQ) + cria radar_faixa e radar_historico';
    }

    public function up(Schema $schema): void
    {
        // Remove versão antiga (estrutura diferente)
        $this->addSql('DROP TABLE IF EXISTS radar_medidor');

        $this->addSql('
            CREATE TABLE radar_medidor (
                id                       BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                sigla_uf                 VARCHAR(2)   NOT NULL,
                estado                   VARCHAR(100) DEFAULT NULL,
                municipio                VARCHAR(150) DEFAULT NULL,
                local_verificacao        VARCHAR(255) DEFAULT NULL,
                data_ultima_verificacao  VARCHAR(20)  DEFAULT NULL,
                data_validade            VARCHAR(20)  DEFAULT NULL,
                ultimo_resultado         VARCHAR(50)  DEFAULT NULL,
                tipo_medidor             VARCHAR(50)  DEFAULT NULL,
                proprietario_nome        VARCHAR(255) DEFAULT NULL,
                proprietario_municipio   VARCHAR(150) DEFAULT NULL,
                proprietario_estado      VARCHAR(2)   DEFAULT NULL,
                faixas_json              JSON         DEFAULT NULL,
                historico_json           JSON         DEFAULT NULL,
                row_hash                 CHAR(64)     NOT NULL,
                identity_hash            CHAR(64)     DEFAULT NULL,
                raw_data                 JSON         DEFAULT NULL,
                imported_at              DATETIME     NOT NULL    COMMENT "(DC2Type:datetime_immutable)",
                updated_at               DATETIME     DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
                PRIMARY KEY (id),
                UNIQUE KEY uq_radar_row_hash       (row_hash),
                INDEX idx_radar_uf                 (sigla_uf),
                INDEX idx_radar_municipio          (municipio),
                INDEX idx_radar_proprietario_nome  (proprietario_nome),
                INDEX idx_radar_ultimo_resultado   (ultimo_resultado),
                INDEX idx_radar_data_validade      (data_validade),
                INDEX idx_radar_identity           (identity_hash)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE radar_faixa (
                id                BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                radar_medidor_id  BIGINT UNSIGNED NOT NULL,
                numero_faixa      VARCHAR(10)  DEFAULT NULL,
                numero_inmetro    VARCHAR(50)  DEFAULT NULL,
                numero_serie      VARCHAR(100) DEFAULT NULL,
                sentido           VARCHAR(50)  DEFAULT NULL,
                velocidade_nominal VARCHAR(20) DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_faixa_radar          (radar_medidor_id),
                INDEX idx_faixa_numero_inmetro (numero_inmetro),
                INDEX idx_faixa_numero_serie   (numero_serie),
                CONSTRAINT fk_faixa_radar FOREIGN KEY (radar_medidor_id)
                    REFERENCES radar_medidor (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE radar_historico (
                id                  BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                radar_medidor_id    BIGINT UNSIGNED NOT NULL,
                numero_certificado  VARCHAR(50)  DEFAULT NULL,
                numero_ensaio       VARCHAR(20)  DEFAULT NULL,
                ano                 VARCHAR(4)   DEFAULT NULL,
                data_laudo          VARCHAR(20)  DEFAULT NULL,
                data_validade       VARCHAR(20)  DEFAULT NULL,
                tipo_servico        VARCHAR(50)  DEFAULT NULL,
                resultado           VARCHAR(50)  DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_hist_radar       (radar_medidor_id),
                INDEX idx_hist_certificado (numero_certificado),
                INDEX idx_hist_ano         (ano),
                CONSTRAINT fk_hist_radar FOREIGN KEY (radar_medidor_id)
                    REFERENCES radar_medidor (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS radar_faixa');
        $this->addSql('DROP TABLE IF EXISTS radar_historico');
        $this->addSql('DROP TABLE IF EXISTS radar_medidor');
    }
}
