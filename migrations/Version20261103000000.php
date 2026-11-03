<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela estabelecimento_saude (CNES) com reserva por usuário';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE estabelecimento_saude (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                cnes VARCHAR(10) NOT NULL,
                nome_fantasia VARCHAR(255) DEFAULT NULL,
                ativo TINYINT(1) DEFAULT 1 NOT NULL,
                uf VARCHAR(2) DEFAULT NULL,
                municipio VARCHAR(255) DEFAULT NULL,
                logradouro VARCHAR(500) DEFAULT NULL,
                telefone VARCHAR(50) DEFAULT NULL,
                latitude NUMERIC(12, 8) DEFAULT NULL,
                longitude NUMERIC(12, 8) DEFAULT NULL,
                link_waze VARCHAR(1000) DEFAULT NULL,
                row_hash VARCHAR(64) NOT NULL,
                imported_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                status VARCHAR(20) DEFAULT NULL,
                reservado_para VARCHAR(100) DEFAULT NULL,
                reservado_em DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_es_cnes (cnes),
                INDEX idx_es_uf_municipio (uf, municipio),
                INDEX idx_es_status (status),
                INDEX idx_es_reservado_para (reservado_para),
                INDEX idx_es_uf_ativo_status_reservado_em (uf, ativo, status, reservado_em),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE estabelecimento_saude');
    }
}
