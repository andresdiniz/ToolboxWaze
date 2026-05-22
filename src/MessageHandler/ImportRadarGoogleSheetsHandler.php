<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportRadarGoogleSheetsMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Importa radares a partir do CSV do Google Sheets.
 *
 * Estratégia de preservação de dados:
 *   - O identity_hash é igual ao do handler RBMLQ (uf + local + tipo_medidor).
 *   - Se o registro já existe no BD (pelo identity_hash), os campos que
 *     NÃO existem no CSV (estado, proprietario_*, historico_json) são
 *     PRESERVADOS — ou seja, o UPDATE não toca nesses campos.
 *   - O row_hash é calculado apenas sobre os campos disponíveis no CSV,
 *     então uma reimportação com os mesmos dados é detectada como "sem mudança".
 *   - Registros importados via RBMLQ que não aparecem no CSV NÃO são
 *     removidos — o import só insere / atualiza, nunca apaga.
 *
 * Campos CSV -> BD:
 *   Município               -> municipio
 *   Data Verificação        -> data_ultima_verificacao
 *   Data Validade           -> data_validade
 *   Resultado               -> ultimo_resultado
 *   Local                   -> local_verificacao
 *   Tipo                    -> tipo_medidor
 *   Faixa + Inmetro + Série + Sentido + Velocidade  -> radar_faixa
 *
 * Campos preservados (sem equivalente no CSV):
 *   estado, proprietario_nome, proprietario_municipio,
 *   proprietario_estado, historico_json
 */
#[AsMessageHandler]
final class ImportRadarGoogleSheetsHandler
{
    private const CURL_TIMEOUT = 120;

    /** Colunas usadas no INSERT (campos que o CSV fornece). */
    private const INSERT_COLS = [
        'sigla_uf', 'municipio', 'local_verificacao',
        'data_ultima_verificacao', 'data_validade', 'ultimo_resultado', 'tipo_medidor',
        'faixas_json',
        'row_hash', 'identity_hash', 'raw_data', 'fonte', 'imported_at', 'updated_at',
    ];

    /**
     * Colunas atualizadas quando o registro já existe.
     * NÃO inclui: estado, proprietario_*, historico_json  ← preservados do RBMLQ.
     */
    private const UPDATE_COLS = [
        'sigla_uf', 'municipio', 'local_verificacao',
        'data_ultima_verificacao', 'data_validade', 'ultimo_resultado', 'tipo_medidor',
        'faixas_json',
        'identity_hash', 'raw_data', 'fonte', 'updated_at',
    ];

    private int $countInserted = 0;
    private int $countUpdated  = 0;
    private int $countSkipped  = 0;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function __invoke(ImportRadarGoogleSheetsMessage $message): void
    {
        $uf         = strtoupper($message->uf);
        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->countInserted = 0;
        $this->countUpdated  = 0;
        $this->countSkipped  = 0;

        $tmpFile = $this->downloadToTempFile($message->getUrl());

        try {
            $this->processCsv($tmpFile, $uf, $importedAt);
        } finally {
            @unlink($tmpFile);
        }

        $total = $this->countInserted + $this->countUpdated + $this->countSkipped;
        echo sprintf(
            "  [%s][sheets] total=%d  inseridos=%d  atualizados=%d  sem-mudança=%d\n",
            $uf, $total, $this->countInserted, $this->countUpdated, $this->countSkipped
        );
    }

    // -------------------------------------------------------------------------
    // Download
    // -------------------------------------------------------------------------

    private function downloadToTempFile(string $url): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'sheets_radar_');

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
            CURLOPT_FAILONERROR    => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $ok      = curl_exec($ch);
        $errCode = curl_errno($ch);
        $errMsg  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $errCode !== 0 || filesize($tmpPath) < 10) {
            @unlink($tmpPath);
            throw new \RuntimeException("cURL erro {$errCode}: {$errMsg} — URL: {$url}");
        }

        return $tmpPath;
    }

    // -------------------------------------------------------------------------
    // Parse CSV
    // -------------------------------------------------------------------------

    private function processCsv(string $path, string $uf, string $importedAt): void
    {
        $fh = fopen($path, 'rb');

        if ($fh === false) {
            throw new \RuntimeException("Não foi possível abrir: {$path}");
        }

        // Detecta e descarta BOM UTF-8 se presente
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        // Lê cabeçalho
        $header = fgetcsv($fh);

        if ($header === false || $header === null) {
            fclose($fh);
            throw new \RuntimeException('CSV sem cabeçalho.');
        }

        // Normaliza nomes das colunas (remove acentos, lowercase)
        $header = array_map([$this, 'normalizeHeader'], $header);

        // Acumula faixas por local+tipo para gerar uma linha por radar
        // (o CSV pode ter múltiplas linhas com o mesmo radar — uma por faixa)
        $radarMap = [];

        while (($row = fgetcsv($fh)) !== false) {
            if ($row === null || count($row) < 2) {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));

            if ($data === false) {
                continue;
            }

            $municipio   = $this->str($data['municipio']         ?? null);
            $local       = $this->str($data['local']             ?? null);
            $tipo        = $this->str($data['tipo']              ?? null);
            $dataVerif   = $this->str($data['dataverificacao']   ?? null);
            $dataValid   = $this->str($data['datavalidade']      ?? null);
            $resultado   = $this->str($data['resultado']         ?? null);

            // identity_hash: mesma lógica do RBMLQ → compatível com dados antigos
            $identityHash = hash('sha256', implode('|', [
                strtoupper($uf),
                strtoupper(trim((string) $local)),
                strtoupper(trim((string) $tipo)),
            ]));

            if (!isset($radarMap[$identityHash])) {
                $radarMap[$identityHash] = [
                    'sigla_uf'                => strtoupper($uf),
                    'municipio'               => $municipio,
                    'local_verificacao'       => $local,
                    'data_ultima_verificacao' => $dataVerif,
                    'data_validade'           => $dataValid,
                    'ultimo_resultado'        => $resultado,
                    'tipo_medidor'            => $tipo,
                    'identity_hash'           => $identityHash,
                    'imported_at'             => $importedAt,
                    'updated_at'              => $importedAt,
                    'fonte'                   => 'google_sheets',
                    '_faixas'                 => [],
                ];
            }

            // Acumula faixas
            $faixa = [
                'NumeroFaixa'       => $this->str($data['faixa']     ?? null),
                'NumeroInmetro'     => $this->str($data['inmetro']   ?? null),
                'NumeroSerie'       => $this->str($data['serie']     ?? null),
                'Sentido'           => $this->str($data['sentido']   ?? null),
                'VelocidadeNominal' => $this->str($data['velocidade'] ?? null),
            ];

            $radarMap[$identityHash]['_faixas'][] = $faixa;
        }

        fclose($fh);

        // Finaliza campos derivados e processa
        foreach ($radarMap as &$radar) {
            $faixasJson = json_encode($radar['_faixas'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // raw_data contém apenas os campos disponíveis nesta fonte
            $rawData = json_encode(array_diff_key($radar, ['_faixas' => 1, 'imported_at' => 1, 'updated_at' => 1, 'fonte' => 1]),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $radar['faixas_json'] = $faixasJson;
            $radar['raw_data']    = $rawData;

            // row_hash calculado sobre campos disponíveis (sem historico, sem proprietario)
            $radar['row_hash'] = hash('sha256', $rawData . $faixasJson);
        }
        unset($radar);

        if ($radarMap !== []) {
            $this->processBatch(array_values($radarMap));
        }
    }

    // -------------------------------------------------------------------------
    // Diff incremental — igual ao RBMLQ mas com UPDATE parcial
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
            foreach ($toInsert as $row) {
                $this->insertRadar($row);
                $this->countInserted++;
            }

            foreach ($toUpdate as $row) {
                // UPDATE parcial: não sobrescreve estado, proprietario_*, historico_json
                $this->updateRadar($row);
                $this->countUpdated++;
            }

            // Sync faixas apenas para registros alterados
            $changed = array_merge($toInsert, $toUpdate);
            foreach ($changed as $row) {
                $radarId = $row['_db_id'] ?? $this->findIdByRowHash($row['row_hash']);

                if ($radarId === null) {
                    continue;
                }

                $this->syncFaixas($radarId, $row['_faixas']);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Queries
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

    private function insertRadar(array $row): void
    {
        $cols      = self::INSERT_COLS;
        $rowHolder = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $params    = [];

        foreach ($cols as $col) {
            $params[] = $row[$col] ?? null;
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT IGNORE INTO radar_medidor (%s) VALUES %s',
                implode(',', $cols),
                $rowHolder
            ),
            $params
        );
    }

    /**
     * UPDATE parcial — preserva campos não disponíveis na nova fonte:
     *   estado, proprietario_nome, proprietario_municipio,
     *   proprietario_estado, historico_json
     */
    private function updateRadar(array $row): void
    {
        $cols     = array_merge(self::UPDATE_COLS, ['row_hash']);
        $setParts = array_map(fn(string $c) => "{$c} = ?", $cols);
        $params   = array_map(fn(string $c) => $row[$c] ?? null, $cols);
        $params[] = $row['_db_id'];

        $this->connection->executeStatement(
            sprintf('UPDATE radar_medidor SET %s WHERE id = ?', implode(', ', $setParts)),
            $params
        );
    }

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

    private function findIdByRowHash(string $rowHash): ?int
    {
        $result = $this->connection->fetchOne(
            'SELECT id FROM radar_medidor WHERE row_hash = ?',
            [$rowHash]
        );

        return $result !== false ? (int) $result : null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Normaliza header CSV: remove acentos, espaços, converte para lowercase.
     * Exemplos: "Data Verificação" => "dataverificacao", "Série" => "serie".
     */
    private function normalizeHeader(string $col): string
    {
        $col = mb_strtolower(trim($col));
        $col = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $col) ?: $col;
        $col = preg_replace('/[^a-z0-9]/', '', $col);

        return $col;
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
