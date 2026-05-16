<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria a tabela `escola` para armazenar as escolas do Censo Escolar (INEP/MEC).
 *
 * Campos:
 *   - co_entidade  : código INEP da escola (8 dígitos, UNIQUE)
 *   - identity_hash: SHA-256 do co_entidade — chave de identidade para diff
 *   - row_hash     : SHA-256 do JSON bruto da linha — detecta qualquer mudança
 *   - raw_data     : JSON bruto da linha para auditoria
 *   - Demais campos: colunas diretas do CSV do Censo Escolar
 */
final class Version20260516150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela escola (Censo Escolar INEP/MEC) com índices para diff incremental';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS escola (
                id                           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                -- Identificação
                co_entidade                  VARCHAR(20)  DEFAULT NULL COMMENT 'Código INEP da escola',
                no_entidade                  VARCHAR(255) DEFAULT NULL COMMENT 'Nome da escola',

                -- Localização geográfica
                co_uf                        VARCHAR(10)  DEFAULT NULL,
                sg_uf                        CHAR(2)      DEFAULT NULL,
                no_uf                        VARCHAR(50)  DEFAULT NULL,
                co_municipio                 VARCHAR(10)  DEFAULT NULL,
                no_municipio                 VARCHAR(100) DEFAULT NULL,
                co_distrito                  VARCHAR(10)  DEFAULT NULL,
                no_distrito                  VARCHAR(100) DEFAULT NULL,

                -- Endereço
                ds_endereco                  VARCHAR(255) DEFAULT NULL,
                nu_endereco                  VARCHAR(20)  DEFAULT NULL,
                ds_complemento               VARCHAR(255) DEFAULT NULL,
                no_bairro                    VARCHAR(100) DEFAULT NULL,
                co_cep                       VARCHAR(10)  DEFAULT NULL,

                -- Contato
                nu_ddd                       VARCHAR(5)   DEFAULT NULL,
                nu_telefone                  VARCHAR(20)  DEFAULT NULL,

                -- Classificação
                tp_dependencia               TINYINT      DEFAULT NULL COMMENT '1=Federal 2=Estadual 3=Municipal 4=Privada',
                tp_categoria_escola_privada  TINYINT      DEFAULT NULL,
                tp_situacao_funcionamento    TINYINT      DEFAULT NULL COMMENT '1=Ativa 2=Paralisada 3=Extinta',

                -- Etapas de ensino
                in_educacao_infantil         TINYINT(1)   DEFAULT NULL,
                in_ensino_fundamental        TINYINT(1)   DEFAULT NULL,
                in_ensino_medio              TINYINT(1)   DEFAULT NULL,
                in_educacao_profissional     TINYINT(1)   DEFAULT NULL,
                in_educacao_especial_exclusiva TINYINT(1) DEFAULT NULL,
                in_eja                       TINYINT(1)   DEFAULT NULL,

                -- Infraestrutura
                in_energia_eletrica          TINYINT(1)   DEFAULT NULL,
                in_agua_potavel              TINYINT(1)   DEFAULT NULL,
                in_esgoto_sanitario          TINYINT(1)   DEFAULT NULL,
                in_lixo_coleta_periodica     TINYINT(1)   DEFAULT NULL,
                in_biblioteca                TINYINT(1)   DEFAULT NULL,
                in_laboratorio_ciencias      TINYINT(1)   DEFAULT NULL,
                in_laboratorio_informatica   TINYINT(1)   DEFAULT NULL,
                in_quadra_esportes_coberta   TINYINT(1)   DEFAULT NULL,
                in_quadra_esportes_descoberta TINYINT(1)  DEFAULT NULL,
                in_internet                  TINYINT(1)   DEFAULT NULL,
                in_banda_larga               TINYINT(1)   DEFAULT NULL,

                -- Geolocalização
                nu_latitude                  VARCHAR(20)  DEFAULT NULL,
                nu_longitude                 VARCHAR(20)  DEFAULT NULL,

                -- Controle de importação (diff incremental)
                row_hash                     CHAR(64)     NOT NULL COMMENT 'SHA-256 do JSON da linha',
                identity_hash                CHAR(64)     NOT NULL COMMENT 'SHA-256 do co_entidade',
                raw_data                     MEDIUMTEXT   DEFAULT NULL,
                imported_at                  DATETIME     NOT NULL,
                updated_at                   DATETIME     NOT NULL,

                -- Índices
                UNIQUE KEY uq_row_hash      (row_hash),
                UNIQUE KEY uq_identity_hash (identity_hash),
                UNIQUE KEY uq_co_entidade   (co_entidade),
                INDEX idx_sg_uf             (sg_uf),
                INDEX idx_co_municipio      (co_municipio),
                INDEX idx_tp_dependencia    (tp_dependencia),
                INDEX idx_tp_situacao       (tp_situacao_funcionamento)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Escolas brasileiras — Censo Escolar INEP/MEC';
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS escola');
    }
}
