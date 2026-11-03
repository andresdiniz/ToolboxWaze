<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103112100 extends AbstractMigration
{
    public function getDescription(): string { return 'Diário de contribuições sem alterar as tabelas de locais'; }
    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('cobertura_contribuicao'), 'Tabela já existe; confira sua origem antes de prosseguir.');
        $this->addSql("CREATE TABLE cobertura_contribuicao (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            referencia VARCHAR(190) NOT NULL, tipo VARCHAR(20) NOT NULL, local_id BIGINT UNSIGNED NOT NULL,
            uf CHAR(2) NOT NULL, actor_id INT DEFAULT NULL, editor VARCHAR(180) NOT NULL,
            username_declarado VARCHAR(100) DEFAULT NULL, origem VARCHAR(20) NOT NULL,
            acao VARCHAR(40) NOT NULL, campo VARCHAR(60) NOT NULL,
            valor_anterior TEXT DEFAULT NULL, valor_novo TEXT DEFAULT NULL,
            ocorrido_em DATETIME NOT NULL, request_id VARCHAR(64) DEFAULT NULL,
            UNIQUE INDEX uniq_contrib_referencia (referencia),
            INDEX idx_contrib_escopo (tipo, uf, ocorrido_em),
            INDEX idx_contrib_actor (actor_id, ocorrido_em),
            INDEX idx_contrib_local (tipo, local_id), PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin ENGINE=InnoDB");
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('O diário preserva autoria e não deve ser apagado por rollback automático.');
    }
}
