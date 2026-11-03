<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispara a importação dos estabelecimentos de saúde (CNES).
 *
 * $fonte pode ser uma URL http(s) de CSV ou o caminho de um arquivo CSV local.
 *
 * Uso:
 *   php bin/console app:import-saude
 *   php bin/console app:import-saude --file=/caminho/cnes.csv
 */
final class ImportSaudeMessage
{
    public function __construct(
        public readonly string $fonte,
    ) {
    }
}
