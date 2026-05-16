<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria as tabelas:
 *   - brazilian_state   : 27 estados com UF, nome e região
 *   - radar_medidor     : medidores RBMLQ/INMETRO de todos os estados
 */
final class Version20260516130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabelas brazilian_state e radar_medidor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE brazilian_state (
                id     INT AUTO_INCREMENT NOT NULL,
                uf     VARCHAR(2)   NOT NULL,
                name   VARCHAR(100) NOT NULL,
                region VARCHAR(20)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bs_uf (uf)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE radar_medidor (
                id                  BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                uf                  VARCHAR(2)   NOT NULL,
                municipio           VARCHAR(100) DEFAULT NULL,
                logradouro          VARCHAR(255) DEFAULT NULL,
                cep                 VARCHAR(20)  DEFAULT NULL,
                nome_empresa        VARCHAR(255) DEFAULT NULL,
                cnpj_empresa        VARCHAR(20)  DEFAULT NULL,
                tipo_medidor        VARCHAR(100) DEFAULT NULL,
                marca_medidor       VARCHAR(100) DEFAULT NULL,
                modelo_medidor      VARCHAR(100) DEFAULT NULL,
                numero_serie        VARCHAR(100) DEFAULT NULL,
                capacidade          VARCHAR(50)  DEFAULT NULL,
                situacao            VARCHAR(20)  DEFAULT NULL,
                data_verificacao    VARCHAR(20)  DEFAULT NULL,
                data_validade       VARCHAR(20)  DEFAULT NULL,
                data_lacre          VARCHAR(20)  DEFAULT NULL,
                lacre               VARCHAR(100) DEFAULT NULL,
                numero_certificado  VARCHAR(100) DEFAULT NULL,
                orgao_verificador   VARCHAR(100) DEFAULT NULL,
                latitude            DECIMAL(10,7) DEFAULT NULL,
                longitude           DECIMAL(10,7) DEFAULT NULL,
                row_hash            CHAR(64)      NOT NULL,
                identity_hash       CHAR(64)      DEFAULT NULL,
                raw_data            JSON          DEFAULT NULL,
                imported_at         DATETIME      NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                updated_at          DATETIME      DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
                PRIMARY KEY (id),
                UNIQUE KEY uq_radar_row_hash      (row_hash),
                INDEX idx_radar_uf                (uf),
                INDEX idx_radar_municipio         (municipio),
                INDEX idx_radar_cnpj              (cnpj_empresa),
                INDEX idx_radar_num_serie         (numero_serie),
                INDEX idx_radar_identity          (identity_hash)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE radar_medidor');
        $this->addSql('DROP TABLE brazilian_state');
    }
}
