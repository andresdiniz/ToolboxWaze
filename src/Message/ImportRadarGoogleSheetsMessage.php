<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem para importação de radares via Google Sheets (CSV publicado).
 *
 * A planilha tem múltiplas abas, cada aba representa um estado (UF).
 * Cada aba é publicada como CSV com o parâmetro ?gid=<gid>.
 *
 * Campos disponíveis na nova fonte (subconjunto do RBMLQ):
 *   Município, Data Verificação, Data Validade, Resultado,
 *   Local, Tipo, Faixa, Inmetro, Série, Sentido, Velocidade
 *
 * Campos do BD que NÃO existem na nova fonte (preservados se já salvos):
 *   estado, proprietario_nome, proprietario_municipio,
 *   proprietario_estado, historico_json
 */
final class ImportRadarGoogleSheetsMessage
{
    /**
     * URL base do CSV publicado — substitua por sua URL.
     * Para selecionar uma aba específica use o parâmetro &gid=<gid_da_aba>.
     *
     * Exemplo com aba única (sem gid, carrega a primeira aba):
     *   https://docs.google.com/spreadsheets/d/e/<ID>/pub?output=csv
     *
     * Exemplo selecionando aba por gid:
     *   https://docs.google.com/spreadsheets/d/e/<ID>/pub?output=csv&gid=12345
     */
    public const BASE_URL = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vS4vA88i8iSLxf0jxatyMI2hHQG_U8AIc5D8qSAMvH2Q8kPS1k3BWxlGCZVNOP3RkwTgNBlp84i-6zx/pub?output=csv';

    /**
     * Mapa de UF => gid da aba correspondente na planilha.
     * Adicione / ajuste conforme as abas da sua planilha.
     * Se a UF não estiver aqui, será usada a aba padrão (sem &gid).
     *
     * Como descobrir o gid: abra a planilha no navegador e veja a URL
     * quando clicar em cada aba: #gid=<número>.
     */
    public const UF_GID_MAP = [
        // 'AC' => '0',    // primeira aba — ajuste os gids reais
        // 'AL' => '12345',
        // 'SP' => '67890',
    ];

    public function __construct(
        /** Sigla do estado: AC, AL, AM ... SP */
        public readonly string $uf,
    ) {
    }

    public function getUrl(): string
    {
        $uf  = strtoupper($this->uf);
        $gid = self::UF_GID_MAP[$uf] ?? null;

        return $gid !== null
            ? self::BASE_URL . '&gid=' . $gid
            : self::BASE_URL;
    }
}
