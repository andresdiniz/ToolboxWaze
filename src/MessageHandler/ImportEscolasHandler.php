<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportEscolasMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Importa as escolas do Censo Escolar (INEP/MEC) por estado.
 *
 * Fontes:
 *   - CSV por UF: https://dadosabertos.mec.gov.br/images/conteudo/escolas/esc_<UF>.csv
 *   - Separador: ponto e vírgula (;)
 *   - Encoding: ISO-8859-1 → convertido para UTF-8
 *   - Atualização: anual (Censo Escolar)
 *
 * Colunas principais do CSV (Censo Escolar 2023/2024):
 *   CO_ENTIDADE, NO_ENTIDADE, CO_UF, SG_UF, NO_UF, CO_MUNICIPIO,
 *   NO_MUNICIPIO, CO_DISTRITO, NO_DISTRITO, DS_ENDERECO, NU_ENDERECO,
 *   DS_COMPLEMENTO, NO_BAIRRO, CO_CEP, NU_DDD, NU_TELEFONE,
 *   TP_DEPENDENCIA, TP_CATEGORIA_ESCOLA_PRIVADA, TP_SITUACAO_FUNCIONAMENTO,
 *   IN_EDUCACAO_INFANTIL, IN_ENSINO_FUNDAMENTAL, IN_ENSINO_MEDIO,
 *   IN_EDUCACAO_PROFISSIONAL, IN_EDUCACAO_ESPECIAL_EXCLUSIVA,
 *   IN_EJA, IN_ENERGIA_ELETRICA, IN_AGUA_POTAVEL, IN_ESGOTO_SANITARIO,
 *   IN_LIXO_COLETA_PERIODICA, IN_BIBLIOTECA, IN_LABORATORIO_CIENCIAS,
 *   IN_LABORATORIO_INFORMATICA, IN_QUADRA_ESPORTES_COBERTA,
 *   IN_QUADRA_ESPORTES_DESCOBERTA, IN_INTERNET, IN_BANDA_LARGA,
 *   NU_LATITUDE, NU_LONGITUDE
 *
 * Diff incremental:
 *   - row_hash    = SHA-256 do JSON da linha inteira (detecta qualquer mudança)
 *   - identity_hash = SHA-256 de (CO_ENTIDADE) — código INEP é imutável
 *   - Linhas com row_hash idêntico ao banco → skip total (zero escrita)
 *   - Linhas com identity_hash existente mas row_hash diferente → UPDATE
 *   - Linhas com identity_hash novo → INSERT
 */
#[AsMessageHandler]
final class ImportEscolasHandler
{
    private const BATCH_SIZE = 100;

    /**
     * Colunas para INSERT (todas).
     * Mantém a mesma ordem que mapRow() retorna.
     */
    private const INSERT_COLS = [
        'co_entidade', 'no_entidade',
        'co_uf', 'sg_uf', 'no_uf',
        'co_municipio', 'no_municipio',
        'co_distrito', 'no_distrito',
        'ds_endereco', 'nu_endereco', 'ds_complemento', 'no_bairro', 'co_cep',
        'nu_ddd', 'nu_telefone',
        'tp_dependencia', 'tp_categoria_escola_privada', 'tp_situacao_funcionamento',
        'in_educacao_infantil', 'in_ensino_fundamental', 'in_ensino_medio',
        'in_educacao_profissional', 'in_educacao_especial_exclusiva',
        'in_eja',
        'in_energia_eletrica', 'in_agua_potavel', 'in_esgoto_sanitario',
        'in_lixo_coleta_periodica',
        'in_biblioteca', 'in_laboratorio_ciencias', 'in_laboratorio_informatica',
        'in_quadra_esportes_coberta', 'in_quadra_esportes_descoberta',
        'in_internet', 'in_banda_larga',
        'nu_latitude', 'nu_longitude',
        'row_hash', 'identity_hash', 'raw_data',
        'imported_at', 'updated_at',
    ];

    /** Colunas que mudam quando a escola é atualizada (imported_at e row_hash ficam fora do SET) */
    private const UPDATE_COLS = [
        'no_entidade',
        'co_uf', 'sg_uf', 'no_uf',
        'co_municipio', 'no_municipio',
        'co_distrito', 'no_distrito',
        'ds_endereco', 'nu_endereco', 'ds_complemento', 'no_bairro', 'co_cep',
        'nu_ddd', 'nu_telefone',
        'tp_dependencia', 'tp_categoria_escola_privada', 'tp_situacao_funcionamento',
        'in_educacao_infantil', 'in_ensino_fundamental', 'in_ensino_medio',
        'in_educacao_profissional', 'in_educacao_especial_exclusiva',
        'in_eja',
        'in_energia_eletrica', 'in_agua_potavel', 'in_esgoto_sanitario',
        'in_lixo_coleta_periodica',
        'in_biblioteca', 'in_laboratorio_ciencias', 'in_laboratorio_informatica',
        'in_quadra_esportes_coberta', 'in_quadra_esportes_descoberta',
        'in_internet', 'in_banda_larga',
        'nu_latitude', 'nu_longitude',
        'row_hash', 'identity_hash', 'raw_data',
        'updated_at',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(ImportEscolasMessage $message): void
    {
        $uf         = strtoupper($message->uf);
        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $tmpFile    = $this->downloadToTempFile($message->getUrl());

        if ($tmpFile === null) {
            return;
        }

        try {
            $this->processFile($tmpFile, $uf, $importedAt);
        } finally {
            @unlink($tmpFile);
        }
    }

    // -------------------------------------------------------------------------
    // Download via cURL → arquivo temporário
    // -------------------------------------------------------------------------

    private function downloadToTempFile(string $url): ?string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'escolas_');

        if ($tmpPath === false) {
            return null;
        }

        $fp = fopen($tmpPath, 'wb');

        if ($fp === false) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'ToolboxWaze/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: text/csv, */*'],
            CURLOPT_FAILONERROR    => true,
        ]);

        $ok      = curl_exec($ch);
        $errCode = curl_errno($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $errCode !== 0 || filesize($tmpPath) < 10) {
            @unlink($tmpPath);
            return null;
        }

        return $tmpPath;
    }

    // -------------------------------------------------------------------------
    // Parse do CSV linha a linha (sem carregar tudo na RAM)
    // -------------------------------------------------------------------------

    private function processFile(string $path, string $uf, string $importedAt): void
    {
        $fh = fopen($path, 'rb');

        if ($fh === false) {
            return;
        }

        $header = null;
        $batch  = [];
        $line   = 0;

        while (!feof($fh)) {
            // fgetcsv lê uma linha por vez → stream, sem alocar o arquivo inteiro
            $raw = fgetcsv($fh, 0, ';', '"', '\\');

            if ($raw === false) {
                continue;
            }

            $line++;

            // Primeira linha = cabeçalho
            if ($header === null) {
                // Converte nomes de colunas para lowercase e remove BOM UTF-8 se houver
                $header = array_map(
                    static fn (string $c): string => strtolower(trim(ltrim($c, "\xEF\xBB\xBF"))),
                    $raw
                );
                continue;
            }

            // Converte ISO-8859-1 → UTF-8 (CSV do INEP é latin1)
            $raw = array_map(
                static fn (?string $v): ?string => $v !== null
                    ? mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1')
                    : null,
                $raw
            );

            // Garante que nunca haja mais valores do que colunas
            if (count($raw) !== count($header)) {
                continue;
            }

            $assoc = array_combine($header, $raw);

            if ($assoc === false || empty($assoc['co_entidade'])) {
                continue;
            }

            $batch[] = $this->mapRow($assoc, $uf, $importedAt);

            if (count($batch) >= self::BATCH_SIZE) {
                $this->processBatch($batch);
                $batch = [];
                gc_collect_cycles();
            }
        }

        fclose($fh);

        if ($batch !== []) {
            $this->processBatch($batch);
        }
    }

    // -------------------------------------------------------------------------
    // Mapeamento: linha do CSV → array normalizado
    // -------------------------------------------------------------------------

    private function mapRow(array $row, string $uf, string $importedAt): array
    {
        // Garante SG_UF mesmo que o CSV não tenha para este registro
        $sgUf = strtoupper($this->str($row['sg_uf'] ?? null) ?? $uf);

        $rawJson = json_encode(
            array_filter($row, static fn ($v) => $v !== null && $v !== ''),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return [
            'co_entidade'                    => $this->str($row['co_entidade']                    ?? null),
            'no_entidade'                    => $this->str($row['no_entidade']                    ?? null),
            'co_uf'                          => $this->str($row['co_uf']                          ?? null),
            'sg_uf'                          => $sgUf,
            'no_uf'                          => $this->str($row['no_uf']                          ?? null),
            'co_municipio'                   => $this->str($row['co_municipio']                   ?? null),
            'no_municipio'                   => $this->str($row['no_municipio']                   ?? null),
            'co_distrito'                    => $this->str($row['co_distrito']                    ?? null),
            'no_distrito'                    => $this->str($row['no_distrito']                    ?? null),
            'ds_endereco'                    => $this->str($row['ds_endereco']                    ?? null),
            'nu_endereco'                    => $this->str($row['nu_endereco']                    ?? null),
            'ds_complemento'                 => $this->str($row['ds_complemento']                 ?? null),
            'no_bairro'                      => $this->str($row['no_bairro']                      ?? null),
            'co_cep'                         => $this->str($row['co_cep']                         ?? null),
            'nu_ddd'                         => $this->str($row['nu_ddd']                         ?? null),
            'nu_telefone'                    => $this->str($row['nu_telefone']                    ?? null),
            'tp_dependencia'                 => $this->str($row['tp_dependencia']                 ?? null),
            'tp_categoria_escola_privada'    => $this->str($row['tp_categoria_escola_privada']    ?? null),
            'tp_situacao_funcionamento'      => $this->str($row['tp_situacao_funcionamento']      ?? null),
            'in_educacao_infantil'           => $this->str($row['in_educacao_infantil']           ?? null),
            'in_ensino_fundamental'          => $this->str($row['in_ensino_fundamental']          ?? null),
            'in_ensino_medio'                => $this->str($row['in_ensino_medio']                ?? null),
            'in_educacao_profissional'       => $this->str($row['in_educacao_profissional']       ?? null),
            'in_educacao_especial_exclusiva' => $this->str($row['in_educacao_especial_exclusiva'] ?? null),
            'in_eja'                         => $this->str($row['in_eja']                         ?? null),
            'in_energia_eletrica'            => $this->str($row['in_energia_eletrica']            ?? null),
            'in_agua_potavel'                => $this->str($row['in_agua_potavel']                ?? null),
            'in_esgoto_sanitario'            => $this->str($row['in_esgoto_sanitario']            ?? null),
            'in_lixo_coleta_periodica'       => $this->str($row['in_lixo_coleta_periodica']       ?? null),
            'in_biblioteca'                  => $this->str($row['in_biblioteca']                  ?? null),
            'in_laboratorio_ciencias'        => $this->str($row['in_laboratorio_ciencias']        ?? null),
            'in_laboratorio_informatica'     => $this->str($row['in_laboratorio_informatica']     ?? null),
            'in_quadra_esportes_coberta'     => $this->str($row['in_quadra_esportes_coberta']     ?? null),
            'in_quadra_esportes_descoberta'  => $this->str($row['in_quadra_esportes_descoberta']  ?? null),
            'in_internet'                    => $this->str($row['in_internet']                    ?? null),
            'in_banda_larga'                 => $this->str($row['in_banda_larga']                 ?? null),
            'nu_latitude'                    => $this->str($row['nu_latitude']                    ?? null),
            'nu_longitude'                   => $this->str($row['nu_longitude']                   ?? null),
            'row_hash'                       => hash('sha256', $rawJson),
            'identity_hash'                  => hash('sha256', (string) ($row['co_entidade'] ?? '')),
            'raw_data'                       => $rawJson,
            'imported_at'                    => $importedAt,
            'updated_at'                     => $importedAt,
        ];
    }

    // -------------------------------------------------------------------------
    // Diff incremental por lote
    // -------------------------------------------------------------------------

    private function processBatch(array $rows): void
    {
        $rowHashes      = array_column($rows, 'row_hash');
        $identityHashes = array_column($rows, 'identity_hash');

        // 1. Quais row_hashes já existem? → idênticos → skip
        $existingRowHashes = $this->fetchExistingRowHashes($rowHashes);

        // 2. Quais identity_hashes já existem? → existentes mas alterados → UPDATE
        $existingByIdentity = $this->fetchExistingByIdentity($identityHashes);

        $toInsert = [];
        $toUpdate = [];

        foreach ($rows as $row) {
            if (\in_array($row['row_hash'], $existingRowHashes, true)) {
                continue; // dado idêntico → zero escrita
            }

            $existing = $existingByIdentity[$row['identity_hash']] ?? null;

            if ($existing === null) {
                $toInsert[] = $row;
            } else {
                $row['_db_id'] = $existing['id'];
                $toUpdate[]    = $row;
            }
        }

        if ($toInsert === [] && $toUpdate === []) {
            return;
        }

        $this->connection->beginTransaction();

        try {
            if ($toInsert !== []) {
                $this->insertBatch($toInsert);
            }

            foreach ($toUpdate as $row) {
                $this->updateEscola($row);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Queries de diff
    // -------------------------------------------------------------------------

    /** @return string[] */
    private function fetchExistingRowHashes(array $rowHashes): array
    {
        if ($rowHashes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($rowHashes), '?'));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT row_hash FROM escola WHERE row_hash IN ({$placeholders})",
            $rowHashes
        );

        return array_column($rows, 'row_hash');
    }

    /** @return array<string, array{id: int, row_hash: string}> */
    private function fetchExistingByIdentity(array $identityHashes): array
    {
        if ($identityHashes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($identityHashes), '?'));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, identity_hash, row_hash FROM escola WHERE identity_hash IN ({$placeholders})",
            $identityHashes
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['identity_hash']] = [
                'id'       => (int) $row['id'],
                'row_hash' => $row['row_hash'],
            ];
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // INSERT em lote
    // -------------------------------------------------------------------------

    private function insertBatch(array $rows): void
    {
        $placeholders = [];
        $params       = [];
        $types        = [];

        foreach ($rows as $i => $row) {
            $rowPlaceholders = [];

            foreach (self::INSERT_COLS as $col) {
                $key               = $col . '_' . $i;
                $rowPlaceholders[] = ':' . $key;
                $params[$key]      = $row[$col] ?? null;
                $types[$key]       = ParameterType::STRING;
            }

            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $sql = sprintf(
            'INSERT INTO escola (%s) VALUES %s',
            implode(', ', self::INSERT_COLS),
            implode(', ', $placeholders)
        );

        $this->connection->executeStatement($sql, $params, $types);
    }

    // -------------------------------------------------------------------------
    // UPDATE individual (escola alterada)
    // -------------------------------------------------------------------------

    private function updateEscola(array $row): void
    {
        $setParts = [];
        $params   = [];
        $types    = [];

        foreach (self::UPDATE_COLS as $col) {
            $setParts[]   = "{$col} = :{$col}";
            $params[$col] = $row[$col] ?? null;
            $types[$col]  = ParameterType::STRING;
        }

        $params['_id'] = $row['_db_id'];
        $types['_id']  = ParameterType::INTEGER;

        $sql = sprintf(
            'UPDATE escola SET %s WHERE id = :_id',
            implode(', ', $setParts)
        );

        $this->connection->executeStatement($sql, $params, $types);
    }

    // -------------------------------------------------------------------------
    // str() helper
    // -------------------------------------------------------------------------

    private function str(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }
}
