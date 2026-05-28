<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem que dispara a importação das escolas do Censo Escolar
 * a partir de um CSV único do Google Sheets (todos os estados).
 *
 * A URL é lida do parâmetro de ambiente ESCOLA_INEP_CSV_URL.
 * Pode ser alterada no .env ou diretamente no banco de configuração.
 *
 * Uso:
 *   php bin/console app:import-escola-inep
 */
final class ImportEscolaInepMessage
{
    public function __construct(
        /** URL completa do CSV público do Google Sheets */
        public readonly string $csvUrl,
    ) {
    }
}
