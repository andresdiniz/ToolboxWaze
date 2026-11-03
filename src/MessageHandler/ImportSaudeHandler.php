<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportSaudeMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Importa estabelecimentos de saúde (CNES) de um CSV (URL ou arquivo local).
 *
 * Guarda só o necessário: CNES, nome, ativo, UF, município, logradouro, telefone e localização.
 * Colunas esperadas (cabeçalho, sem distinção de acento/maiúscula):
 *   CNES, NOME FANTASIA (ou RAZÃO SOCIAL), STATUS DO ESTABELECIMENTO, UF, MUNICÍPIO,
 *   LOGRADOURO, TELEFONE, LOCATION ("-8.217 -35.229")  — ou LATITUDE / LONGITUDE separadas.
 *
 * Estratégia (mesma das escolas): leitura linha a linha, lotes pequenos em transação e diff incremental
 * por row_hash. Os campos de reserva (status, reservado_para, reservado_em) nunca são tocados.
 * Separador (vírgula, ponto e vírgula ou tab) é detectado pelo cabeçalho.
 */
#[AsMessageHandler]
final class ImportSaudeHandler
{
    private const BATCH_SIZE = 200;

    private const TABLE = 'estabelecimento_saude';

    private const INSERT_COLS = [
        'cnes', 'nome_fantasia', 'ativo', 'uf', 'municipio', 'logradouro', 'telefone',
        'latitude', 'longitude', 'row_hash', 'imported_at', 'updated_at',
    ];

    private const UPDATE_COLS = [
        'nome_fantasia', 'ativo', 'uf', 'municipio', 'logradouro', 'telefone',
        'latitude', 'longitude', 'row_hash', 'updated_at',
    ];

    /** cabeçalho normalizado → chave interna */
    private const COL_MAP = [
        'cnes'                      => 'cnes',
        'nome fantasia'             => 'nome',
        'razao social'              => 'razao_social',
        'status do estabelecimento' => 'status',
        'uf'                        => 'uf',
        'municipio'                 => 'municipio',
        'logradouro'                => 'logradouro',
        'telefone'                  => 'telefone',
        'location'                  => 'location',
        'latitude'                  => 'latitude',
        'longitude'                 => 'longitude',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{lidas: int, validas: int}
     */
    public function __invoke(ImportSaudeMessage $message): array
    {
        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $fonte      = $message->fonte;

        if (is_file($fonte)) {
            return $this->processFile($fonte, $importedAt);
        }

        $tmpFile = $this->downloadToTempFile($fonte);

        try {
            return $this->processFile($tmpFile, $importedAt);
        } finally {
            @unlink($tmpFile);
        }
    }

    private function downloadToTempFile(string $url): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'saude_cnes_');

        if ($tmpPath === false) {
            throw new \RuntimeException('Não foi possível criar arquivo temporário.');
        }

        $fp = fopen($tmpPath, 'wb');

        if ($fp === false) {
            throw new \RuntimeException('Não foi possível abrir o arquivo temporário.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT      => 'ToolboxWaze/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: text/csv, */*'],
            CURLOPT_FAILONERROR    => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $ok      = curl_exec($ch);
        $errCode = curl_errno($ch);
        $errMsg  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $errCode !== 0 || filesize($tmpPath) < 50) {
            @unlink($tmpPath);

            throw new \RuntimeException("Falha ao baixar o CSV (cURL {$errCode}): {$errMsg}");
        }

        return $tmpPath;
    }

    /**
     * @return array{lidas: int, validas: int}
     */
    private function processFile(string $path, string $importedAt): array
    {
        $fh = fopen($path, 'rb');

        if ($fh === false) {
            throw new \RuntimeException('Não foi possível abrir o arquivo CSV.');
        }

        $header  = null;
        $keyMap  = [];
        $sep     = ',';
        $batch   = [];
        $lidas   = 0;
        $validas = 0;

        try {
            while (!feof($fh)) {
                if ($header === null) {
                    $first = fgets($fh);

                    if ($first === false) {
                        break;
                    }

                    $first = ltrim($first, "\xEF\xBB\xBF");
                    $sep   = $this->detectSeparator($first);
                    $raw   = str_getcsv(rtrim($first, "\r\n"), $sep, '"', '\\');

                    $header = $raw;

                    foreach ($header as $pos => $col) {
                        $keyMap[$pos] = self::COL_MAP[$this->normalizeHeader((string) $col)] ?? null;
                    }

                    if (!in_array('cnes', $keyMap, true)) {
                        throw new \RuntimeException('Coluna CNES não encontrada no cabeçalho do CSV.');
                    }

                    continue;
                }

                $raw = fgetcsv($fh, 0, $sep, '"', '\\');

                if ($raw === false || $raw === [null]) {
                    continue;
                }

                ++$lidas;

                if (count($raw) !== count($header)) {
                    continue;
                }

                $assoc = [];

                foreach ($raw as $pos => $val) {
                    $key = $keyMap[$pos] ?? null;

                    if ($key !== null) {
                        $assoc[$key] = $this->str($val);
                    }
                }

                $row = $this->buildRow($assoc, $importedAt);

                if ($row === null) {
                    continue;
                }

                ++$validas;
                $batch[$row['cnes']] = $row; // CNES repetido no arquivo: vale a última linha

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->processBatch(array_values($batch));
                    $batch = [];
                    gc_collect_cycles();
                }
            }
        } finally {
            fclose($fh);
        }

        if ($batch !== []) {
            $this->processBatch(array_values($batch));
        }

        return ['lidas' => $lidas, 'validas' => $validas];
    }

    private function detectSeparator(string $headerLine): string
    {
        $best  = ',';
        $count = -1;

        foreach ([',', ';', "\t"] as $candidate) {
            $n = substr_count($headerLine, $candidate);

            if ($n > $count) {
                $count = $n;
                $best  = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param array<string, string|null> $row
     *
     * @return array<string, string|null>|null
     */
    private function buildRow(array $row, string $importedAt): ?array
    {
        $cnes = $row['cnes'] ?? null;

        if ($cnes === null) {
            return null;
        }

        $cnes = preg_replace('/\D+/', '', $cnes) ?? '';

        if ($cnes === '') {
            return null;
        }

        $cnes = str_pad($cnes, 7, '0', STR_PAD_LEFT);

        [$lat, $lng] = $this->parseLocation($row);

        $status = isset($row['status']) ? $this->normalizeHeader($row['status']) : 'ativo';

        $dados = [
            'cnes'          => $cnes,
            'nome_fantasia' => $row['nome'] ?? $row['razao_social'] ?? null,
            'ativo'         => $status === 'ativo' ? '1' : '0',
            'uf'            => isset($row['uf']) ? strtoupper(mb_substr($row['uf'], 0, 2)) : null,
            'municipio'     => $row['municipio'] ?? null,
            'logradouro'    => $row['logradouro'] ?? null,
            'telefone'      => $row['telefone'] ?? null,
            'latitude'      => $lat,
            'longitude'     => $lng,
        ];

        $dados['row_hash']    = hash('sha256', (string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $dados['imported_at'] = $importedAt;
        $dados['updated_at']  = $importedAt;

        return $dados;
    }

    /**
     * Aceita "location" no formato "-8.21736134 -35.22963424" (ou separado por vírgula/;)
     * ou as colunas latitude/longitude separadas.
     *
     * @param array<string, string|null> $row
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseLocation(array $row): array
    {
        $lat = null;
        $lng = null;

        if (!empty($row['location'])) {
            $partes = preg_split('/[\s,;]+/', trim((string) $row['location']), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($partes) >= 2) {
                $lat = $partes[0];
                $lng = $partes[1];
            }
        } else {
            $lat = $row['latitude'] ?? null;
            $lng = $row['longitude'] ?? null;
        }

        if ($lat === null || $lng === null) {
            return [null, null];
        }

        $lat = str_replace(',', '.', $lat);
        $lng = str_replace(',', '.', $lng);

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return [null, null];
        }

        $latF = (float) $lat;
        $lngF = (float) $lng;

        if ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180 || ($latF === 0.0 && $lngF === 0.0)) {
            return [null, null];
        }

        return [number_format($latF, 8, '.', ''), number_format($lngF, 8, '.', '')];
    }

    /**
     * @param list<array<string, string|null>> $rows
     */
    private function processBatch(array $rows): void
    {
        $existentes = $this->fetchExistentes(array_column($rows, 'cnes'));

        $toInsert = [];
        $toUpdate = [];

        foreach ($rows as $row) {
            $existente = $existentes[$row['cnes']] ?? null;

            if ($existente === null) {
                $toInsert[] = $row;
            } elseif ($existente['row_hash'] !== $row['row_hash']) {
                $row['_db_id'] = (string) $existente['id'];
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
                $this->updateRow($row);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /**
     * @param list<string|null> $cnesList
     *
     * @return array<string, array{id: int, row_hash: string}>
     */
    private function fetchExistentes(array $cnesList): array
    {
        $placeholders = implode(',', array_fill(0, count($cnesList), '?'));

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, cnes, row_hash FROM ' . self::TABLE . " WHERE cnes IN ({$placeholders})",
            $cnesList,
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['cnes']] = ['id' => (int) $row['id'], 'row_hash' => (string) $row['row_hash']];
        }

        return $map;
    }

    /**
     * @param list<array<string, string|null>> $rows
     */
    private function insertBatch(array $rows): void
    {
        $grupo  = '(' . implode(', ', array_fill(0, count(self::INSERT_COLS), '?')) . ')';
        $params = [];

        foreach ($rows as $row) {
            foreach (self::INSERT_COLS as $col) {
                $params[] = $row[$col] ?? null;
            }
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            self::TABLE,
            implode(', ', self::INSERT_COLS),
            implode(', ', array_fill(0, count($rows), $grupo)),
        );

        $this->connection->executeStatement($sql, $params);
    }

    /**
     * @param array<string, string|null> $row
     */
    private function updateRow(array $row): void
    {
        $set    = [];
        $params = [];

        foreach (self::UPDATE_COLS as $col) {
            $set[]    = "{$col} = ?";
            $params[] = $row[$col] ?? null;
        }

        $params[] = (int) $row['_db_id'];

        $this->connection->executeStatement(
            sprintf('UPDATE %s SET %s WHERE id = ?', self::TABLE, implode(', ', $set)),
            $params,
        );
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim(ltrim($value, "\xEF\xBB\xBF")), 'UTF-8');
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    /** Normaliza valor de célula: trim, converte Latin-1 se preciso e trata "Não informado" como nulo. */
    private function str(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $str = trim((string) $value);

        if ($str === '') {
            return null;
        }

        if (!mb_check_encoding($str, 'UTF-8')) {
            $str = mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
        }

        return $this->normalizeHeader($str) === 'nao informado' ? null : $str;
    }
}
