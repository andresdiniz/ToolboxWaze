<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem que dispara a importação das escolas do Censo Escolar (INEP)
 * para um único estado.
 *
 * O CSV do INEP é separado por ponto e vírgula (;) e codificado em
 * ISO-8859-1 — o handler trata a conversão para UTF-8.
 *
 * URL do arquivo por UF:
 *   https://dadosabertos.mec.gov.br/images/conteudo/escolas/esc_<UF>.csv
 *
 * Caso o MEC mude o caminho, basta atualizar BASE_URL aqui.
 *
 * Uso direto (sem fila):
 *   php bin/console app:import-escolas
 *   php bin/console app:import-escolas --uf=MG
 */
final class ImportEscolasMessage
{
    /**
     * URL do CSV de escolas por UF disponibilizado pelo MEC/INEP.
     * Fonte: https://dadosabertos.mec.gov.br
     */
    public const BASE_URL = 'https://dadosabertos.mec.gov.br/images/conteudo/escolas/esc_%s.csv';

    public function __construct(
        /** Sigla do estado: AC, AL, AM ... SP */
        public readonly string $uf,
    ) {
    }

    public function getUrl(): string
    {
        return sprintf(self::BASE_URL, strtoupper($this->uf));
    }
}
