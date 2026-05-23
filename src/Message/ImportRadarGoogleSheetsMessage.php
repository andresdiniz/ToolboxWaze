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
     * URL base do CSV publicado.
     *
     * Para selecionar uma aba específica use o parâmetro &gid=<gid_da_aba>.
     * Como descobrir o gid: abra a planilha no navegador e clique em cada
     * aba — o número aparece na URL: #gid=<número>.
     */
    public const BASE_URL = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vS4vA88i8iSLxf0jxatyMI2hHQG_U8AIc5D8qSAMvH2Q8kPS1k3BWxlGCZVNOP3RkwTgNBlp84i-6zx/pub?output=csv';

    /**
     * Mapa de UF => gid da aba correspondente na planilha.
     *
     * Nota: SP, DF e TO foram deixados sem gid — usarão a aba padrão
     * (sem &gid). Preencha quando tiver os gids corretos.
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
