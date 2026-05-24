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
 *
 * ─────────────────────────────────────────────────────────────────────
 * PRIORIDADE DE URL (ordem decrescente):
 *
 *   1. $customUrl passado diretamente na construção (mais alta prioridade)
 *      → usado pelo ImportRadarMedidoresCommand quando o estado tem
 *        link_base_radares preenchido no banco (BrazilianState).
 *
 *   2. UF_GID_MAP com BASE_URL (fallback hardcoded)
 *      → mantido para compatibilidade e para UFs sem link no banco.
 * ─────────────────────────────────────────────────────────────────────
 */
final class ImportRadarGoogleSheetsMessage
{
    /**
     * URL base do CSV publicado (fallback).
     *
     * Para selecionar uma aba específica use o parâmetro &gid=<gid_da_aba>.
     */
    public const BASE_URL = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vS4vA88i8iSLxf0jxatyMI2hHQG_U8AIc5D8qSAMvH2Q8kPS1k3BWxlGCZVNOP3RkwTgNBlp84i-6zx/pub?output=csv';

    /**
     * Mapa fallback de UF => gid (usado quando link_base_radares é NULL no banco).
     */
    public const UF_GID_MAP = [
        'AC' => '1827024199',
        'AL' => '615899950',
        'AM' => '279389996',
        'AP' => '1337174808',
        'BA' => '973830127',
        'CE' => '158670580',
        // 'DF' => '',   // gid pendente
        'ES' => '17948801',
        'GO' => '256806712',
        'MA' => '1874573610',
        'MG' => '750233625',
        'MS' => '154970032',
        'MT' => '794262885',
        'PA' => '771805655',
        'PB' => '1250233818',
        'PE' => '1954639181',
        'PI' => '1262412902',
        'PR' => '2052829212',
        'RJ' => '1880219963',
        'RN' => '1196895486',
        'RO' => '794263067',
        'RR' => '1848043218',
        'RS' => '1570302815',
        'SC' => '1070009330',
        'SE' => '473072021',
        'SP' => '1492817692',
        'TO' => '846230006',
    ];

    public function __construct(
        /** Sigla do estado: AC, AL, AM ... SP */
        public readonly string $uf,
        /**
         * URL customizada vinda do banco (BrazilianState::linkBaseRadares).
         * Quando preenchida, sobrepõe o BASE_URL + UF_GID_MAP.
         */
        public readonly ?string $customUrl = null,
    ) {
    }

    /**
     * Retorna a URL final de download do CSV.
     *
     * Prioridade:
     *   1. $customUrl (link do banco) → retorna direto.
     *   2. UF_GID_MAP → BASE_URL + &gid=...
     *   3. BASE_URL sem gid (aba padrão da planilha).
     */
    public function getUrl(): string
    {
        if ($this->customUrl !== null && $this->customUrl !== '') {
            return $this->customUrl;
        }

        $uf  = strtoupper($this->uf);
        $gid = self::UF_GID_MAP[$uf] ?? null;

        return $gid !== null
            ? self::BASE_URL . '&gid=' . $gid
            : self::BASE_URL;
    }
}
