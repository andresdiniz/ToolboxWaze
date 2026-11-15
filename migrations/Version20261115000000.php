<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dispositivos do userscript WME (token aleatório gerado pelo script, confirmado por e-mail).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migration requer MySQL/MariaDB com InnoDB.'
        );

        $this->addSql(<<<'SQL'
CREATE TABLE wme_dispositivo (
    id INT AUTO_INCREMENT NOT NULL,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    estado VARCHAR(20) NOT NULL,
    verificacao_hash VARCHAR(64) DEFAULT NULL,
    verificacao_expira_em DATETIME DEFAULT NULL,
    verificacao_enviada_em DATETIME DEFAULT NULL,
    apelido_declarado VARCHAR(120) DEFAULT NULL,
    script_version VARCHAR(20) DEFAULT NULL,
    ip_hash VARCHAR(64) DEFAULT NULL,
    criado_em DATETIME NOT NULL,
    ativado_em DATETIME DEFAULT NULL,
    ultimo_uso_em DATETIME DEFAULT NULL,
    UNIQUE INDEX UNIQ_WME_DISP_TOKEN (token_hash),
    INDEX idx_wme_disp_user (user_id),
    INDEX idx_wme_disp_ip (ip_hash, criado_em),
    PRIMARY KEY (id),
    CONSTRAINT FK_WME_DISP_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wme_dispositivo');
    }
}
