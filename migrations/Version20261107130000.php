<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261107130000 extends AbstractMigration
{
    public function getDescription(): string { return 'Ledger transacional dos envios WME.'; }
    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Esta migration requer MySQL/MariaDB com InnoDB.');
        $this->addSql("CREATE TABLE wme_envio (id VARCHAR(80) NOT NULL, user_id INT NOT NULL, hash VARCHAR(64) NOT NULL, estado VARCHAR(20) NOT NULL, solicitacao_id INT DEFAULT NULL, criado_em DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE wme_envio'); }
}
