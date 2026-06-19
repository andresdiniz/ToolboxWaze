<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportRadarMedidoresMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Importa medidores RBMLQ de um estado com diff incremental.
 *
 * Suporta 3 formatos de resposta da API RBMLQ:
 *   1. Um objeto JSON por linha dentro de um array  (parse linha-por-linha)
 *   2. Pretty-printed com espaços antes de {       (ltrim antes de testar)
 *   3. Array JSON compacto numa linha só            (fallback json_decode)
 *
 * identity_hash: SHA-256 de UF|MUNICIPIO|LOGRADOURO|TIPO|SERIE_FAIXA_0
 */
#[AsMessageHandler]
final class ImportRadarMedidoresHandler
{
    private const BATCH_SIZE   = 30;
    private const CURL_TIMEOUT = 600;

    /**
     * Colunas reais da tabela radar_medidor usadas no INSERT.
     * Mapeadas a partir da entity RadarMedidor.
     */
    private const RADAR_INSERT_COLS = [
        'uf',
        'sigla_uf',
        'municipio',
        'logradouro',
        'tipo_medidor',
        'situacao',
        'nome_empresa',
        'data_ultima_verificacao',
        'data_validade',
        'data_verificacao_efetiva',
        'row_hash',
        'identity_hash',
        'raw_data',
        'imported_at',
        'updated_at',
    ];

    private const RADAR_UPDATE_COLS = [
        'uf',
        'sigla_uf',
        'municipio',
        'logradouro',
        'tipo_medidor',
        'situacao',
        'nome_empresa',
        'data_ultima_verificacao',
        'data_validade',
        'data_verificacao_efetiva',
        'identity_hash',
        'raw_data',
        'updated_at',
    ];

    private int $countInserted = 0;
    private int $countUpdated  = 0;
    private int $countSkipped  = 0;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function __invoke(ImportRadarMedidoresMessage $message): void
    {
        $uf         = strtoupper($message->uf);
        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->countInserted = 0;
        $this->countUpdated  = 0;
        $this->countSkipped  = 0;

        $tmpFile = $this->downloadToTempFile($message->getUrl());

        try {
            $parsed = $this->processFile($tmpFile, $uf, $importedAt);

            if ($parsed === 0) {
                $this->saveDebugCopy($tmpFile, $uf);
                $parsed = $this->processFileFallback($tmpFile, $uf, $importedAt);
            }

            if ($parsed === 0) {
                echo sprintf(
                    "  [%s] AVISO: nenhum item parseado. Verifique var/log/rbmlq_%s_*.json\n",
                    $uf, $uf
                );
            }
        } finally {
            @unlink($tmpFile);
        }

        $total = $this->countInserted + $this->countUpdated + $this->countSkipped;
        echo sprintf(
            "  [%s] total=%d  inseridos=%d  atualizados=%d  sem-mudança=%d\n",
            $uf, $total, $this->countInserted, $this->countUpdated, $this->countSkipped
        );
    }

    // -------------------------------------------------------------------------
    // Download cURL → arquivo temporário
    // -------------------------------------------------------------------------

    private function downloadToTempFile(string $url): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'rbmlq_');

        if ($tmpPath === false) {
            throw new \RuntimeException('Não foi possível criar arquivo temporário.');
        }

        $fp = fopen($tmpPath, 'wb');

        if ($fp === false) {
            throw new \RuntimeException('Não foi possível abrir arquivo temporário para escrita.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT      => 'ToolboxWaze/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_FAILONERROR    => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $ok      = curl_exec($ch);
        $errCode = curl_errno($ch);
        $errMsg  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $errCode !== 0 || filesize($tmpPath) < 3) {
            @unlink($tmpPath);
            throw new \RuntimeException("cURL erro {$errCode}: {$errMsg} — URL: {$url}");
        }

        return $tmpPath;
    }

    // -------------------------------------------------------------------------
    // Estrategia 1: linha-por-linha com ltrim() (pretty-print + compacto)
    // -------------------------------------------------------------------------

    private function processFile(string $path, string $uf, string $importedAt): int
    {
        $fh = fopen($path, 'rb');

        if ($fh === false) {
            throw new \RuntimeException("Não foi possível abrir: {$path}");
        }

        $batch  = [];
        $parsed = 0;

        while (!feof($fh)) {
            $line = fgets($fh);

            if ($line === false) {
                break;
            }

            $line = rtrim(ltrim($line), ',');

            if ($line === '' || $line === '[' || $line === ']') {
                continue;
            }

            if (($line[0] ?? '') !== '{') {
                continue;
            }

            $item = json_decode($line, true);

            if (!is_array($item) || $item === []) {
                continue;
            }

            $parsed++;
            $batch[] = $this->mapItem($item, $uf, $importedAt);
            unset($item);

            if (count($batch) >= self::BATCH_SIZE) {
                $this->processBatch($batch);
                $batch = [];
            }
        }

        fclose($fh);

        if ($batch !== []) {
            $this->processBatch($batch);
        }

        return $parsed;
    }

    // -------------------------------------------------------------------------
    // Estrategia 2 (fallback): json_decode do arquivo inteiro
    // -------------------------------------------------------------------------

    private function processFileFallback(string $path, string $uf, string $importedAt): int
    {
        $content = file_get_contents($path);

        if ($content === false || trim($content) === '') {
            return 0;
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            return 0;
        }

        // Array direto [...] ou objeto raiz com array dentro
        if (isset($data[0])) {
            $items = $data;
        } else {
            $items = null;
            foreach ($data as $v) {
                if (is_array($v) && isset($v[0]) && is_array($v[0])) {
                    $items = $v;
                    break;
                }
            }
            if ($items === null) {
                return 0;
            }
        }

        $batch  = [];
        $parsed = 0;

        foreach ($items as $item) {
            if (!is_array($item) || $item === []) {
                continue;
            }

            $parsed++;
            $batch[] = $this->mapItem($item, $uf, $importedAt);
            unset($item);

            if (count($batch) >= self::BATCH_SIZE) {
                $this->processBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->processBatch($batch);
        }

        unset($data, $items, $content);

        return $parsed;
    }

    // -------------------------------------------------------------------------
    // Salva cópia raw para debug
    // -------------------------------------------------------------------------

    private function saveDebugCopy(string $tmpPath, string $uf): void
    {
        $logDir = dirname(__DIR__, 2) . '/var/log';

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $dest = sprintf('%s/rbmlq_%s_%s.json', $logDir, $uf, date('Ymd_His'));
        @copy($tmpPath, $dest);

        echo sprintf("  [%s] Raw JSON salvo em: %s\n", $uf, $dest);
    }

    // -------------------------------------------------------------------------
    // Mapeamento item JSON RBMLQ -> colunas reais da tabela radar_medidor
    //
    // Campos RBMLQ conhecidos:
    //   SiglaUf, Estado, Municipio, LocalVerificacao, TipoMedidor,
    //   UltimoResultado, DataUltimaVerificacao, DataValidade,
    //   Proprietario { Nome, Municipio, Estado, Cnpj }
    //   Faixas[]     { NumeroFaixa, NumeroInmetro, NumeroSerie, Sentido, VelocidadeNominal }
    //   Historico[]  { NumeroCertificado, NumeroEnsaio, Ano, DataLaudo, DataValidade, TipoServico, Resultado }
    // -------------------------------------------------------------------------

    private function mapItem(array $item, string $uf, string $importedAt): array
    {
        $prop      = \is_array($item['Proprietario'] ?? null) ? $item['Proprietario'] : [];
        $faixas    = \is_array($item['Faixas']       ?? null) ? $item['Faixas']       : [];
        $historico = \is_array($item['Historico']    ?? null) ? $item['Historico']    : [];

        $rawJson  = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $siglaUf  = strtoupper($this->str($item['SiglaUf'] ?? null) ?? $uf);

        $dataVerif = $this->str($item['DataUltimaVerificacao'] ?? null);
        $dataValid = $this->str($item['DataValidade']          ?? null);

        return [
            // Colunas da tabela real
            'uf'                         => $siglaUf,
            'sigla_uf'                   => $siglaUf,
            'municipio'                  => $this->str($item['Municipio']        ?? null),
            'logradouro'                 => $this->str($item['LocalVerificacao'] ?? null),
            'tipo_medidor'               => $this->str($item['TipoMedidor']      ?? null),
            'situacao'                   => $this->str($item['UltimoResultado']  ?? null),
            'nome_empresa'               => $this->str($prop['Nome']             ?? null),
            'data_ultima_verificacao'    => $dataVerif,
            'data_validade'              => $dataValid,
            'data_verificacao_efetiva'   => $this->resolveDataVerificacao($dataVerif, $dataValid),
            'row_hash'                   => hash('sha256', $rawJson),
            'identity_hash'              => $this->buildIdentityHash($item, $siglaUf, $faixas),
            'raw_data'                   => $rawJson,
            'imported_at'                => $importedAt,
            'updated_at'                 => $importedAt,
            // Internos (não vão ao BD direto)
            '_faixas'                    => $faixas,
            '_historico'                 => $historico,
        ];
    }

    // -------------------------------------------------------------------------
    // Lote com diff incremental
    // -------------------------------------------------------------------------

    private function processBatch(array $rows): void
    {
        $rowHashes      = array_column($rows, 'row_hash');
        $identityHashes = array_column($rows, 'identity_hash');

        $existingRowHashes  = $this->fetchExistingRowHashes($rowHashes);
        $existingByIdentity = $this->fetchExistingByIdentity($identityHashes);

        $toInsert = [];
        $toUpdate = [];

        foreach ($rows as $row) {
            if (\in_array($row['row_hash'], $existingRowHashes, true)) {
                $this->countSkipped++;
                continue;
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
            foreach ($toInsert as &$row) {
                $row['_db_id'] = $this->insertOne($row);
            }
            unset($row);
            $this->countInserted += count($toInsert);

            foreach ($toUpdate as $row) {
                $this->updateRadar($row);
                $this->countUpdated++;
            }

            $changed = array_merge($toInsert, $toUpdate);
            foreach ($changed as $row) {
                if (($row['_db_id'] ?? null) === null) {
                    continue;
                }

                $this->syncFaixas((int) $row['_db_id'], $row['_faixas']);
                $this->syncHistorico((int) $row['_db_id'], $row['_historico']);
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

    private function fetchExistingRowHashes(array $rowHashes): array
    {
        if ($rowHashes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($rowHashes), '?'));

        return array_column(
            $this->connection->fetchAllAssociative(
                "SELECT row_hash FROM radar_medidor WHERE row_hash IN ({$placeholders})",
                $rowHashes
            ),
            'row_hash'
        );
    }

    private function fetchExistingByIdentity(array $identityHashes): array
    {
        if ($identityHashes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($identityHashes), '?'));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, identity_hash, row_hash FROM radar_medidor WHERE identity_hash IN ({$placeholders})",
            $identityHashes
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['identity_hash']] = ['id' => (int) $row['id'], 'row_hash' => $row['row_hash']];
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // INSERT individual
    // -------------------------------------------------------------------------

    private function insertOne(array $row): int
    {
        $cols   = self::RADAR_INSERT_COLS;
        $holder = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $params = array_map(fn(string $c) => $row[$c] ?? null, $cols);

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO radar_medidor (%s) VALUES %s',
                implode(',', $cols),
                $holder
            ),
            $params
        );

        return (int) $this->connection->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // UPDATE individual
    // -------------------------------------------------------------------------

    private function updateRadar(array $row): void
    {
        $cols     = array_merge(self::RADAR_UPDATE_COLS, ['row_hash']);
        $setParts = array_map(fn(string $c) => "{$c} = ?", $cols);
        $params   = array_map(fn(string $c) => $row[$c] ?? null, $cols);
        $params[] = $row['_db_id'];

        $this->connection->executeStatement(
            sprintf('UPDATE radar_medidor SET %s WHERE id = ?', implode(', ', $setParts)),
            $params
        );
    }

    // -------------------------------------------------------------------------
    // Sync faixas
    // -------------------------------------------------------------------------

    private function syncFaixas(int $radarId, array $faixas): void
    {
        $this->connection->executeStatement(
            'DELETE FROM radar_faixa WHERE radar_medidor_id = ?',
            [$radarId]
        );

        foreach ($faixas as $faixa) {
            if (!\is_array($faixa)) {
                continue;
            }

            $this->connection->executeStatement(
                'INSERT INTO radar_faixa
                    (radar_medidor_id, numero_faixa, numero_inmetro, numero_serie, sentido, velocidade_nominal)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $radarId,
                    $this->str($faixa['NumeroFaixa']       ?? null),
                    $this->str($faixa['NumeroInmetro']     ?? null),
                    $this->str($faixa['NumeroSerie']       ?? null),
                    $this->str($faixa['Sentido']           ?? null),
                    $this->str($faixa['VelocidadeNominal'] ?? null),
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Sync histórico
    // -------------------------------------------------------------------------

    private function syncHistorico(int $radarId, array $historico): void
    {
        $this->connection->executeStatement(
            'DELETE FROM radar_historico WHERE radar_medidor_id = ?',
            [$radarId]
        );

        foreach ($historico as $h) {
            if (!\is_array($h)) {
                continue;
            }

            $this->connection->executeStatement(
                'INSERT INTO radar_historico
                    (radar_medidor_id, numero_certificado, numero_ensaio, ano, data_laudo, data_validade, tipo_servico, resultado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $radarId,
                    $this->str($h['NumeroCertificado'] ?? null),
                    $this->str($h['NumeroEnsaio']      ?? null),
                    $this->str($h['Ano']               ?? null),
                    $this->str($h['DataLaudo']         ?? null),
                    $this->str($h['DataValidade']      ?? null),
                    $this->str($h['TipoServico']       ?? null),
                    $this->str($h['Resultado']         ?? null),
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildIdentityHash(array $item, string $uf, array $faixas): string
    {
        $serie0 = '';
        if (!empty($faixas[0]) && \is_array($faixas[0])) {
            $serie0 = strtoupper(trim((string) ($faixas[0]['NumeroSerie'] ?? '')));
        }

        return hash('sha256', implode('|', [
            strtoupper($uf),
            strtoupper(trim((string) ($item['Municipio']        ?? ''))),
            strtoupper(trim((string) ($item['LocalVerificacao'] ?? ''))),
            strtoupper(trim((string) ($item['TipoMedidor']      ?? ''))),
            $serie0,
        ]));
    }

    private function resolveDataVerificacao(?string $dataVerif, ?string $dataValid): ?string
    {
        if ($dataVerif !== null && $dataVerif !== '') {
            return $dataVerif;
        }

        if ($dataValid !== null && $dataValid !== '') {
            $dt = \DateTimeImmutable::createFromFormat('d/m/Y', $dataValid);

            if ($dt !== false) {
                return $dt->modify('-1 year')->format('d/m/Y');
            }
        }

        return null;
    }

    private function str(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (\is_array($value) || \is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        $str = trim((string) $value);

        if ($str !== '' && !mb_check_encoding($str, 'UTF-8')) {
            $str = mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
        }

        return $str === '' ? null : $str;
    }
}
