<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * radar_waze_link.permanent_hazard_id estava 0 (ou desatualizado) em links cadastrados pela tela do radar,
 * porque RadarService::saveWazeLink não gravava a coluna. O ID verdadeiro está no próprio link
 * (permanentHazards=NÚMERO): recalcula o ID só onde ele não bate com o link.
 *
 * Não altera links sem um permanentHazards numérico entre 1 e 4294967295 (limite do INT UNSIGNED).
 */
final class Version20261124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corrige radar_waze_link.permanent_hazard_id (0 ou desatualizado) a partir do link salvo';
    }

    public function up(Schema $schema): void
    {
        // Texto entre "permanentHazards=" e o próximo "&" (ou o fim do link).
        $hazard = "SUBSTRING_INDEX(SUBSTRING_INDEX(waze_link, 'permanentHazards=', -1), '&', 1)";

        $this->addSql(
            "UPDATE radar_waze_link
             SET permanent_hazard_id = CAST($hazard AS UNSIGNED)
             WHERE (waze_link LIKE '%?permanentHazards=%' OR waze_link LIKE '%&permanentHazards=%')
               AND $hazard REGEXP '^[0-9]{1,10}$'
               AND CAST($hazard AS UNSIGNED) BETWEEN 1 AND 4294967295
               AND permanent_hazard_id <> CAST($hazard AS UNSIGNED)"
        );
    }
}
