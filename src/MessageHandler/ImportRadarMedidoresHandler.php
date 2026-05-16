<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportRadarMedidoresMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Importa medidores RBMLQ de um estado com diff incremental.
 *
 * Fluxo por lote:
 *   1. Calcula row_hash (SHA-256 do JSON bruto do item) para cada linha.
 *   2. Consulta o banco: quais desses row_hashes JÁ existem?
 *      → Idênticos: zero escrita (skip total).
 *      → Ausentes no banco: INSERT IGNORE (novo radar).
 *      → identity_hash existe mas row_hash diferente: UPDATE (radar mudou).
 *   3. Faixas e histórico só são recriados para radares que de fato mudaram.
 */
#[AsMessageHandler]
final class ImportRadarMedidoresHandler
{
    /** Registros por lote de INSERT/UPDATE — maior = menos round-trips ao BD */
    private const BATCH_SIZE = 200;

    /** Timeout total do download por estado (segundos) — estados grandes como SP podem ser lentos */
    private const CURL_TIMEOUT = 600;

    private const RADAR_INSERT_COLS = [
        'sigla_uf', 'estado', 'municipio', 'local_verificacao',
        'data_ultima_verificacao', 'data_validade', 'ultimo_resultado', 'tipo_medidor',
        'proprietario_nome', 'proprietario_municipio', 'proprietario_estado',
        'faixas_json', 'historico_json',
        'row_hash', 'identity_hash', 'raw_data', 'imported_at', 'updated_at',
    ];

    private const RADAR_UPDATE_COLS = [
        'sigla_uf', 'estado', 'municipio', 'local_verificacao',
        'data_ultima_verificacao', 'data_validade', 'ultimo_resultado', 'tipo_medidor',
        'proprietario_nome', 'proprietario_municipio', 'proprietario_estado',
        'faixas_json', 'historico_json',
        'identity_hash', 'raw_data', 'updated_at',
    ];

    /** Contadores para relatório por estado */
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

        if ($tmpFile === null) {
            throw new \RuntimeException("Falha no download para UF={$uf}: {$message->getUrl()}");
        }

        try {
            $this->processFile($tmpFile, $uf, $importedAt);
        } finally {
            @unlink($tmpFile);
        }

        // Imprime sumário (visível no CLI via --verbose)
        $total = $this->countInserted + $this->countUpdated + $this->countSkipped;
        echo sprintf(
            "  [%s] total=%d  inseridos=%d  atualizados=%d  sem-mudança=%d\n",
            $uf,
            $total,
            $this->countInserted,
            $this->countUpdated,
            $this->countSkipped
        );
    }

    // -------------------------------------------------------------------------
    // Download via cURL → arquivo temporário (stream, zero RAM extra)
    // -------------------------------------------------------------------------

    private function downloadToTempFile(string $url): ?string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'rbmlq_');

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
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT      => 'ToolboxWaze/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_FAILONERROR    => true,
            CURLOPT_SSL_VERIFYPEER => false, // alguns ambientes de hospedagem têm CA desatualizada
        ]);

        $ok      = curl_exec($ch);
        $errCode = curl_errno($ch);
        $errMsg  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $errCode !== 0 || filesize($tmpPath) < 3) {
            @unlink($tmpPath);
            // Relança como exceção para o command logar o estado com erro
            throw new \RuntimeException("cURL erro {$errCode}: {$errMsg} — URL: {$url}");
        }

        return $tmpPath;
    }

    // -------------------------------------------------------------------------
    // Parse incremental: extrai objetos JSON {..} do arquivo um a um
    // -------------------------------------------------------------------------

    private function processFile(string $path, string $uf, string $importedAt): void
    {
        $fh = fopen($path, 'rb');

        if ($fh === false) {
            return;
        }

        $buffer   = '';
        $depth    = 0;
        $inString = false;
        $escape   = false;
        $batch    = [];

        while (!feof($fh)) {
            $chunk   = fread($fh, 65536);
            $buffer .= $chunk;
            $out      = '';
            $objStart = null;
            $len      = strlen($buffer);

            for ($i = 0; $i < $len; $i++) {
                $c = $buffer[$i];

                if ($escape) { $escape = false; continue; }
                if ($c === '\\' && $inString) { $escape = true; continue; }
                if ($c === '"') { $inString = !$inString; continue; }
                if ($inString) { continue; }

                if ($c === '{') {
                    if ($depth === 0) { $objStart = $i; }
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;

                    if ($depth === 0 && $objStart !== null) {
                        $objJson = substr($buffer, $objStart, $i - $objStart + 1);
                        $item    = json_decode($objJson, true);

                        if (\is_array($item) && $item !== []) {
                            $batch[] = $this->mapItem($item, $uf, $importedAt);

                            if (count($batch) >= self::BATCH_SIZE) {
                                $this->processBatch($batch);
                                $batch = [];
                                gc_collect_cycles();
                            }
                        }

                        $out      = substr($buffer, $i + 1);
                        $objStart = null;
                    }
                }
            }

            if ($objStart !== null) {
                $buffer = substr($buffer, $objStart);
            } elseif ($out !== '') {
                $buffer = $out;
            } elseif (strlen($buffer) > 256) {
                $buffer = substr($buffer, -256);
            }
        }

        fclose($fh);

        if ($batch !== []) {
            $this->processBatch($batch);
        }
    }

    // -------------------------------------------------------------------------
    // Mapeamento
    // -------------------------------------------------------------------------

    private function mapItem(array $item, string $uf, string $importedAt): array
    {
        $prop      = \is_array($item['Proprietario'] ?? null) ? $item['Proprietario'] : [];
        $faixas    = \is_array($item['Faixas']       ?? null) ? $item['Faixas']       : [];
        $historico = \is_array($item['Historico']    ?? null) ? $item['Historico']    : [];

        $rawJson       = json_encode($item,      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $faixasJson    = json_encode($faixas,    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $historicoJson = json_encode($historico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $siglaUf       = strtoupper($this->str($item['SiglaUf'] ?? null) ?? $uf);

        return [
            'sigla_uf'                => $siglaUf,
            'estado'                  => $this->str($item['Estado']                ?? null),
            'municipio'               => $this->str($item['Municipio']             ?? null),
            'local_verificacao'       => $this->str($item['LocalVerificacao']      ?? null),
            'data_ultima_verificacao' => $this->str($item['DataUltimaVerificacao'] ?? null),
            'data_validade'           => $this->str($item['DataValidade']          ?? null),
            'ultimo_resultado'        => $this->str($item['UltimoResultado']       ?? null),
            'tipo_medidor'            => $this->str($item['TipoMedidor']           ?? null),
            'proprietario_nome'       => $this->str($prop['Nome']                  ?? null),
            'proprietario_municipio'  => $this->str($prop['Municipio']             ?? null),
            'proprietario_estado'     => $this->str($prop['Estado']                ?? null),
            'faixas_json'             => $faixasJson,
            'historico_json'          => $historicoJson,
            'row_hash'                => hash('sha256', $rawJson),
            'identity_hash'           => $this->buildIdentityHash($item, $siglaUf),
            'raw_data'                => $rawJson,
            'imported_at'             => $importedAt,
            'updated_at'              => $importedAt,
            '_faixas'                 => $faixas,
            '_historico'              => $historico,
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
                $toInsert[];
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
                $this->countInserted += count($toInsert);
            }

            foreach ($toUpdate as $row) {
                $this->updateRadar($row);
                $this->countUpdated++;
            }

            $changed = array_merge($toInsert, $toUpdate);
            foreach ($changed as $row) {
                $radarId = $row['_db_id'] ?? $this->findIdByRowHash($row['row_hash']);

                if ($radarId === null) {
                    continue;
                }

                $this->syncFaixas($radarId, $row['_faixas']);
                $this->syncHistorico($radarId, $row['_historico']);
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

        $rows = $this->connection->fetchAllAssociative(
            "SELECT row_hash FROM radar_medidor WHERE row_hash IN ({$placeholders})",
            $rowHashes
        );

        return array_column($rows, 'row_hash');
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
            $map[$row['identity_hash']] = [
                'id'       => (int) $row['id'],
                'row_hash' => $row['row_hash'],
            ];
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // INSERT IGNORE em lote
    // -------------------------------------------------------------------------

    private function insertBatch(array $rows): void
    {
        $placeholders = [];
        $params       = [];
        $types        = [];

        foreach ($rows as $i => $row) {
            $rowPlaceholders = [];

            foreach (self::RADAR_INSERT_COLS as $col) {
                $key               = $col . '_' . $i;
                $rowPlaceholders[] = ':' . $key;
                $params[$key]      = $row[$col] ?? null;
                $types[$key]       = ParameterType::STRING;
            }

            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $sql = sprintf(
            'INSERT IGNORE INTO radar_medidor (%s) VALUES %s',
            implode(', ', self::RADAR_INSERT_COLS),
            implode(', ', $placeholders)
        );

        $this->connection->executeStatement($sql, $params, $types);
    }

    // -------------------------------------------------------------------------
    // UPDATE individual
    // -------------------------------------------------------------------------

    private function updateRadar(array $row): void
    {
        $setParts = [];
        $params   = [];
        $types    = [];

        foreach (self::RADAR_UPDATE_COLS as $col) {
            $setParts[]   = "{$col} = :{$col}";
            $params[$col] = $row[$col] ?? null;
            $types[$col]  = ParameterType::STRING;
        }

        $setParts[]         = 'row_hash = :row_hash';
        $params['row_hash'] = $row['row_hash'];
        $types['row_hash']  = ParameterType::STRING;

        $params['_id'] = $row['_db_id'];
        $types['_id']  = ParameterType::INTEGER;

        $sql = sprintf(
            'UPDATE radar_medidor SET %s WHERE id = :_id',
            implode(', ', $setParts)
        );

        $this->connection->executeStatement($sql, $params, $types);
    }

    // -------------------------------------------------------------------------
    // Sync faixas e histórico
    // -------------------------------------------------------------------------

    private function syncFaixas(int $radarId, array $faixas): void
    {
        $this->connection->executeStatement(
            'DELETE FROM radar_faixa WHERE radar_medidor_id = ?',
            [$radarId]
        );

        foreach ($faixas as $faixa) {
            if (!\is_array($faixa)) { continue; }

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

    private function syncHistorico(int $radarId, array $historico): void
    {
        $this->connection->executeStatement(
            'DELETE FROM radar_historico WHERE radar_medidor_id = ?',
            [$radarId]
        );

        foreach ($historico as $h) {
            if (!\is_array($h)) { continue; }

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

    private function findIdByRowHash(string $rowHash): ?int
    {
        $result = $this->connection->fetchOne(
            'SELECT id FROM radar_medidor WHERE row_hash = ?',
            [$rowHash]
        );

        return $result !== false ? (int) $result : null;
    }

    private function buildIdentityHash(array $item, string $uf): string
    {
        $parts = [
            strtoupper($uf),
            strtoupper(trim((string) ($item['LocalVerificacao'] ?? ''))),
            strtoupper(trim((string) ($item['TipoMedidor']     ?? ''))),
        ];

        return hash('sha256', implode('|', $parts));
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
