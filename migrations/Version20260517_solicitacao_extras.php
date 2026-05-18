<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\Database\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517_solicitacao_extras extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tabelas de histórico, comentários e notificações; expande coluna status da solicitação';
    }

    public function up(Schema $schema): void
    {
        // Expande coluna status de VARCHAR(16) para VARCHAR(32)
        $this->addSql('ALTER TABLE solicitacoes MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT \'pendente\'');

        // Histórico de status
        $this->addSql(<<<SQL
            CREATE TABLE solicitacao_historicos (
                id              INT AUTO_INCREMENT NOT NULL,
                solicitacao_id  INT NOT NULL,
                autor_id        INT DEFAULT NULL,
                status_anterior VARCHAR(32) DEFAULT NULL,
                status_novo     VARCHAR(32) NOT NULL,
                nota            LONGTEXT DEFAULT NULL,
                criado_em       DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_hist_sol (solicitacao_id),
                CONSTRAINT fk_hist_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes (id) ON DELETE CASCADE,
                CONSTRAINT fk_hist_autor FOREIGN KEY (autor_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Comentários / chat interno
        $this->addSql(<<<SQL
            CREATE TABLE solicitacao_comentarios (
                id                   INT AUTO_INCREMENT NOT NULL,
                solicitacao_id       INT NOT NULL,
                autor_id             INT DEFAULT NULL,
                autor_nome_externo   VARCHAR(255) DEFAULT NULL,
                mensagem             LONGTEXT NOT NULL,
                interno              TINYINT(1) NOT NULL DEFAULT 0,
                criado_em            DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_coment_sol (solicitacao_id),
                CONSTRAINT fk_coment_sol FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes (id) ON DELETE CASCADE,
                CONSTRAINT fk_coment_autor FOREIGN KEY (autor_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Notificações internas
        $this->addSql(<<<SQL
            CREATE TABLE notificacoes (
                id              INT AUTO_INCREMENT NOT NULL,
                usuario_id      INT NOT NULL,
                solicitacao_id  INT DEFAULT NULL,
                tipo            VARCHAR(64) NOT NULL,
                mensagem        LONGTEXT NOT NULL,
                lida            TINYINT(1) NOT NULL DEFAULT 0,
                criada_em       DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_notif_usuario_lida (usuario_id, lida),
                CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id) REFERENCES `user` (id) ON DELETE CASCADE,
                CONSTRAINT fk_notif_sol     FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS notificacoes');
        $this->addSql('DROP TABLE IF EXISTS solicitacao_comentarios');
        $this->addSql('DROP TABLE IF EXISTS solicitacao_historicos');
        $this->addSql('ALTER TABLE solicitacoes MODIFY COLUMN status VARCHAR(16) NOT NULL DEFAULT \'pendente\'');
    }
}
