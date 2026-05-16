<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria a tabela fuel_reseller_raw para armazenar os dados brutos do CSV da ANP.
 *
 * Campos mantidos como VARCHAR/TEXT preservam o formato original sem conversão.
 * row_hash   = SHA-256 de toda a linha (detecta qualquer mudança)
 * identity_hash = SHA-256 de razao_social + endereco + cep + uf + municipio
 *                 (base para detectar postos que trocaram de CNPJ sem mudar de local)
 * raw_data   = JSON com todos os campos originais
 */
final class Version20260516000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela fuel_reseller_raw — dados brutos ANP';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE fuel_reseller_raw (
                id              BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                codigo_isimp    VARCHAR(20)  DEFAULT NULL,
                autorizacao     VARCHAR(50)  DEFAULT NULL,
                data_publicacao VARCHAR(20)  DEFAULT NULL,
                razao_social    VARCHAR(255) DEFAULT NULL,
                cnpj            VARCHAR(20)  DEFAULT NULL,
                endereco        VARCHAR(255) DEFAULT NULL,
                complemento     VARCHAR(255) DEFAULT NULL,
                bairro          VARCHAR(255) DEFAULT NULL,
                cep             VARCHAR(20)  DEFAULT NULL,
                uf              VARCHAR(2)   DEFAULT NULL,
                municipio       VARCHAR(255) DEFAULT NULL,
                bandeira        VARCHAR(255) DEFAULT NULL,
                data_vinculacao VARCHAR(20)  DEFAULT NULL,
                nome_fantasia   VARCHAR(255) DEFAULT NULL,
                row_hash        CHAR(64)     NOT NULL,
                identity_hash   CHAR(64)     DEFAULT NULL,
                raw_data        JSON         DEFAULT NULL,
                imported_at     DATETIME     NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                PRIMARY KEY (id),
                INDEX idx_frr_cnpj         (cnpj),
                INDEX idx_frr_uf_municipio (uf, municipio),
                INDEX idx_frr_row_hash     (row_hash),
                INDEX idx_frr_identity     (identity_hash)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fuel_reseller_raw');
    }
}
