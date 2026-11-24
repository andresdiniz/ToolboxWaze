<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\WazeLinkParser;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * radar_waze_link.permanent_hazard_id estava 0 (ou desatualizado) em links cadastrados pela tela do radar,
 * porque RadarService::saveWazeLink não gravava a coluna. O ID verdadeiro está no próprio link
 * (permanentHazards=NÚMERO): esta migration o recalcula para todos os registros.
 */
final class Version20261124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corrige radar_waze_link.permanent_hazard_id (0 ou desatualizado) a partir do link salvo';
    }

    public function up(Schema $schema): void
    {
        // O ajuste é feito em PHP (postUp) para não depender da versão do MySQL/MariaDB.
        $this->addSql('SELECT 1');
    }

    public function postUp(Schema $schema): void
    {
        $corrigidos = 0;
        $semId      = 0;

        foreach ($this->connection->fetchAllAssociative('SELECT id, waze_link, permanent_hazard_id FROM radar_waze_link') as $row) {
            $hazardId = WazeLinkParser::extractHazardId((string) $row['waze_link']);

            if ($hazardId === null || $hazardId < 1 || $hazardId > 4294967295) {
                ++$semId; // link sem permanentHazards válido: não há o que recalcular

                continue;
            }

            if ($hazardId !== (int) $row['permanent_hazard_id']) {
                $this->connection->executeStatement(
                    'UPDATE radar_waze_link SET permanent_hazard_id = ? WHERE id = ?',
                    [$hazardId, $row['id']]
                );
                ++$corrigidos;
            }
        }

        $this->write(sprintf('radar_waze_link: %d registro(s) corrigido(s); %d sem permanentHazards válido no link.', $corrigidos, $semId));
    }

    public function down(Schema $schema): void
    {
        // Correção de dados: não há como (nem por que) restaurar os valores incorretos.
    }
}
