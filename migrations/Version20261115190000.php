<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria preferencias, eventos e entregas de notificacoes por e-mail.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE notificacao_preferencia (
    id INT AUTO_INCREMENT NOT NULL,
    user_id INT NOT NULL,
    email_habilitado TINYINT(1) NOT NULL DEFAULT 1,
    tipos JSON NOT NULL,
    atualizado_em DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX UNIQ_NOTIF_PREF_USER (user_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE notificacao_preferencia ADD CONSTRAINT FK_NOTIF_PREF_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
CREATE TABLE notificacao_evento (
    id INT AUTO_INCREMENT NOT NULL,
    tipo VARCHAR(64) NOT NULL,
    titulo VARCHAR(180) NOT NULL,
    corpo TEXT NOT NULL,
    link VARCHAR(500) DEFAULT NULL,
    entidade_tipo VARCHAR(40) DEFAULT NULL,
    entidade_id INT DEFAULT NULL,
    criado_por_id INT DEFAULT NULL,
    criado_em DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_notif_evento_tipo (tipo),
    INDEX idx_notif_evento_entidade (entidade_tipo, entidade_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE notificacao_evento ADD CONSTRAINT FK_NOTIF_EVENTO_CRIADOR FOREIGN KEY (criado_por_id) REFERENCES user (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
CREATE TABLE notificacao_envio_email (
    id INT AUTO_INCREMENT NOT NULL,
    evento_id INT NOT NULL,
    user_id INT NOT NULL,
    email VARCHAR(180) NOT NULL,
    estado VARCHAR(20) NOT NULL,
    tentativas SMALLINT NOT NULL DEFAULT 0,
    ultimo_erro VARCHAR(500) DEFAULT NULL,
    criado_em DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    enviado_em DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    proxima_tentativa_em DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX UNIQ_NOTIF_ENVIO_EVENTO_USER (evento_id, user_id),
    INDEX idx_notif_envio_estado (estado, proxima_tentativa_em),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE notificacao_envio_email ADD CONSTRAINT FK_NOTIF_ENVIO_EVENTO FOREIGN KEY (evento_id) REFERENCES notificacao_evento (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notificacao_envio_email ADD CONSTRAINT FK_NOTIF_ENVIO_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notificacao_envio_email DROP FOREIGN KEY FK_NOTIF_ENVIO_EVENTO');
        $this->addSql('ALTER TABLE notificacao_envio_email DROP FOREIGN KEY FK_NOTIF_ENVIO_USER');
        $this->addSql('ALTER TABLE notificacao_evento DROP FOREIGN KEY FK_NOTIF_EVENTO_CRIADOR');
        $this->addSql('ALTER TABLE notificacao_preferencia DROP FOREIGN KEY FK_NOTIF_PREF_USER');
        $this->addSql('DROP TABLE notificacao_envio_email');
        $this->addSql('DROP TABLE notificacao_evento');
        $this->addSql('DROP TABLE notificacao_preferencia');
    }
}
