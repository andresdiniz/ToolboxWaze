<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem que dispara a importação do CSV de revendedores de combustíveis da ANP.
 */
final class ImportFuelResellersMessage
{
    public const ANP_URL = 'https://www.gov.br/anp/pt-br/centrais-de-conteudo/dados-abertos/arquivos/arquivos-dados-cadastrais-dos-revendedores-varejistas-de-combustiveis-automotivos/dados-cadastrais-revendedores-varejistas-combustiveis-automoveis.csv';

    public function __construct(
        public readonly string $url = self::ANP_URL
    ) {
    }
}
