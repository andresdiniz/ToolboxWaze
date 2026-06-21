<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Form Builder — cria as tabelas form_builder, form_builder_campo, form_builder_resposta.
 * Execute: php bin/console doctrine:migrations:migrate
 *
 * ⚠ Se você preferir fazer via make:migration, delete este arquivo e rode:
 *   php bin/console doctrine:migrations:diff
 */
final class Version_FormBuilder extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabelas do Form Builder dinâmico';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE form_builder (
                id              INT AUTO_INCREMENT NOT NULL,
                criado_por_id   INT DEFAULT NULL,
                nome            VARCHAR(120) NOT NULL,
                descricao       VARCHAR(255) DEFAULT NULL,
                slug            VARCHAR(80) NOT NULL,
                configuracoes   JSON DEFAULT NULL,
                ativo           TINYINT(1) NOT NULL DEFAULT 1,
                criado_em       DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                atualizado_em   DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_FORM_SLUG (slug),
                INDEX IDX_FORM_CRIADO_POR (criado_por_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE form_builder_campo (
                id              INT AUTO_INCREMENT NOT NULL,
                formulario_id   INT NOT NULL,
                chave           VARCHAR(80) NOT NULL,
                label           VARCHAR(120) NOT NULL,
                tipo            VARCHAR(30) NOT NULL DEFAULT 'text',
                ordem           INT NOT NULL DEFAULT 0,
                obrigatorio     TINYINT(1) NOT NULL DEFAULT 0,
                placeholder     VARCHAR(255) DEFAULT NULL,
                ajuda           VARCHAR(500) DEFAULT NULL,
                opcoes          JSON DEFAULT NULL,
                valor_padrao    VARCHAR(500) DEFAULT NULL,
                INDEX IDX_CAMPO_FORM (formulario_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_CAMPO_FORM FOREIGN KEY (formulario_id)
                    REFERENCES form_builder (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE form_builder_resposta (
                id              INT AUTO_INCREMENT NOT NULL,
                formulario_id   INT NOT NULL,
                usuario_id      INT DEFAULT NULL,
                submissao_uuid  VARCHAR(36) NOT NULL,
                dados           JSON NOT NULL,
                criado_em       DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                ip              VARCHAR(45) DEFAULT NULL,
                status          VARCHAR(20) NOT NULL DEFAULT 'pendente',
                nota_admin      LONGTEXT DEFAULT NULL,
                INDEX IDX_RESP_FORM (formulario_id),
                INDEX IDX_RESP_USER (usuario_id),
                INDEX IDX_RESP_STATUS (status),
                PRIMARY KEY(id),
                CONSTRAINT FK_RESP_FORM FOREIGN KEY (formulario_id)
                    REFERENCES form_builder (id) ON DELETE CASCADE,
                CONSTRAINT FK_RESP_USER FOREIGN KEY (usuario_id)
                    REFERENCES user (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE form_builder ADD CONSTRAINT FK_FB_CRIADO_POR FOREIGN KEY (criado_por_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE form_builder DROP FOREIGN KEY FK_FB_CRIADO_POR');
        $this->addSql('DROP TABLE form_builder_resposta');
        $this->addSql('DROP TABLE form_builder_campo');
        $this->addSql('DROP TABLE form_builder');
    }
}
