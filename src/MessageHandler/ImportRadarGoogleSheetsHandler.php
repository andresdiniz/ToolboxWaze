<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\RadarManual;
use App\Message\ImportRadarGoogleSheetsMessage;
use App\Repository\RadarManualRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Importa radares a partir do CSV do Google Sheets.
 *
 * ════════════════════════════════════════════════════════════
 * ESTRATÉGIA DE PRESERVAÇÃO DE DADOS
 * ════════════════════════════════════════════════════════════
 *
 * O identity_hash é idêntico ao usado pelo ImportRadarMedidoresHandler:
 *   SHA-256( UF | LOCAL_VERIFICACAO | TIPO_MEDIDOR )
 *
 * Isso garante que um radar já importado via RBMLQ seja reconhecido
 * e atualizado — e não duplicado — ao usar a fonte Google Sheets.
 *
 * O row_hash desta fonte é calculado APENAS sobre os campos
 * disponíveis no CSV (sem proprietario_*, sem historico_json).
 * Portanto, ao trocar de volta para o RBMLQ, o row_hash muda e o
 * handler RBMLQ atualiza o registro restaurando todos os campos.
 *
 * Campos atualizados por esta fonte:
 *   municipio, local_verificacao, data_ultima_verificacao,
 *   data_validade, data_verificacao_efetiva, ultimo_resultado,
 *   tipo_medidor, faixas_json, identity_hash, raw_data,
 *   row_hash, updated_at
 *
 * Campos PRESERVADOS (não tocados pelo UPDATE):
 *   estado, proprietario_nome, proprietario_municipio,
 *   proprietario_estado, historico_json
 *
 * ════════════════════════════════════════════════════════════
 * MERGE AUTOMÁTICO DE RADARES MANUAIS
 * ════════════════════════════════════════════════════════════
 *
 * Após processar cada lote, o handler verifica se algum
 * identity_hash presente no CSV bate com um RadarManual pendente.
 * Quando há match:
 *   1. O radar_medidor recém-inserido/atualizado é vinculado.
 *   2. O RadarManual tem status = 'mesclado', mesclado_em = now,
 *      radar_medidor_id = id do registro oficial.
 *
 * ════════════════════════════════════════════════════════════
 * ESTRUTURA DO CSV
 * ════════════════════════════════════════════════════════════
 *
 * Cabeçalho esperado:
 *   Município, Data Verificação, Data Validade, Resultado,
 *   Local, Tipo, Faixa, Inmetro, Série, Sentido, Velocidade
 */
#[AsMessageHandler]
final class ImportRadarGoogleSheetsHandler
{
    private const CURL_TIMEOUT = 120;
    private const BATCH_SIZE   = 100;

    private const INSERT_COLS = [
        'sigla_uf', 'municipio', 'local_verificacao',
        'data_ultima_verificacao', 'data_validade', 'data_verificacao_efetiva',
        'ultimo_resultado', 'tipo_medidor',
        'faixas_json',
        'row_hash', 'identity_hash', 'raw_data', 'imported_at', 'updated_at',
    ];

    private const UPDATE_COLS = [
        'sigla_uf', 'municipio', 'local_verificacao',
        'data_ultima_verificacao', 'data_validade', 'data_verificacao_efetiva',
        'ultimo_resultado', 'tipo_medidor',
        'faixas_json',
        'identity_hash', 'raw_data', 'updated_at',
    ];

    private int $countInserted = 0;
    private int $countUpdated  = 0;
    private int $countSkipped  = 0;
    private int $countMerged   = 0;

    public function __construct(
        private readonly Connection            $connection,
        private readonly EntityManagerInterface $em,
        private readonly RadarManualRepository  $radarManualRepo,
    ) {}

    public function __invoke(ImportRadarGoogleSheetsMessage $message): void
    {
        $uf         = strtoupper($message->uf);
        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->countInserted = 0;
        $this->countUpdated  = 0;
        $this->countSkipped  = 0;
        $this->countMerged   = 0;

        $tmpFile = $this->downloadToTempFile($message->getUrl());

        try {
            $radarMap = $this->parseCsv($tmpFile, $uf, $importedAt);
        } finally {
            @unlink($tmpFile);
        }

        if ($radarMap === []) {
            echo sprintf("  [%s][sheets] CSV vazio ou sem dados válidos\n", $uf);
            return;
        }

        foreach (array_chunk(array_values($radarMap), self::BATCH_SIZE) as $batch) {
            $this->processBatch($batch);
        }

        $total = $this->countInserted + $this->countUpdated + $this->countSkipped;
        echo sprintf(
            "  [%s][sheets] total=%d  inseridos=%d  atualizados=%d  sem-mudança=%d  merges=%d\n",
            $uf, $total, $this->countInserted, $this->countUpdated, $this->countSkipped, $this->countMerged
        );
    }

    // ════════════════════════════════════════════════════════════
    // Download
    // ════════════════════════════════════════════════════════════

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

    // ════════════════════════════════════════════════════════════
    // Parse CSV — agrupa múltiplas linhas (faixas) por radar
    // ════════════════════════════════════════════════════════════

    private function parseCsv(string $path, string $uf, string $importedAt): array
    {
        $fh = fopen($path, 'rb');

        if ($fh === false) {
            throw new \RuntimeException("Não foi possível abrir: {$path}");
        }

        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $rawHeader = fgetcsv($fh);

        if ($rawHeader === false || $rawHeader === null) {
            fclose($fh);
            throw new \RuntimeException('CSV sem cabeçalho.');
        }

        $header = array_map([$this, 'normalizeKey'], $rawHeader);

        $radarMap = [];

        while (($csvRow = fgetcsv($fh)) !== false) {
            if ($csvRow === null || count($csvRow) < 2) {
                continue;
            }

            $data = array_combine($header, array_pad($csvRow, count($header), null));

            if ($data === false) {
                continue;
            }

            $municipio = $this->str($data['municipio']       ?? null);
            $local     = $this->str($data['local']           ?? null);
            $tipo      = $this->str($data['tipo']            ?? null);
            $dataVerif = $this->str($data['dataverificacao'] ?? null);
            $dataValid = $this->str($data['datavalidade']    ?? null);
            $resultado = $this->str($data['resultado']       ?? null);

            $identityHash = $this->buildIdentityHash($uf, $local, $tipo);

            if (!isset($radarMap[$identityHash])) {
                $radarMap[$identityHash] = [
                    'sigla_uf'                  => strtoupper($uf),
                    'municipio'                 => $municipio,
                    'local_verificacao'         => $local,
                    'data_ultima_verificacao'   => $dataVerif,
                    'data_validade'             => $dataValid,
                    'data_verificacao_efetiva'  => $this->resolveDataVerificacao($dataVerif, $dataValid),
                    'ultimo_resultado'          => $resultado,
                    'tipo_medidor'              => $tipo,
                    'identity_hash'             => $identityHash,
                    'imported_at'               => $importedAt,
                    'updated_at'                => $importedAt,
                    '_faixas'                   => [],
                ];
            }

            $radarMap[$identityHash]['_faixas'][] = [
                'NumeroFaixa'       => $this->str($data['faixa']      ?? null),
                'NumeroInmetro'     => $this->str($data['inmetro']    ?? null),
                'NumeroSerie'       => $this->str($data['serie']      ?? null),
                'Sentido'           => $this->str($data['sentido']    ?? null),
                'VelocidadeNominal' => $this->str($data['velocidade'] ?? null),
            ];
        }

        fclose($fh);

        foreach ($radarMap as &$radar) {
            $faixasJson = json_encode($radar['_faixas'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $rawPayload = [
                'sigla_uf'                  => $radar['sigla_uf'],
                'municipio'                 => $radar['municipio'],
                'local_verificacao'         => $radar['local_verificacao'],
                'data_ultima_verificacao'   => $radar['data_ultima_verificacao'],
                'data_validade'             => $radar['data_validade'],
                'data_verificacao_efetiva'  => $radar['data_verificacao_efetiva'],
                'ultimo_resultado'          => $radar['ultimo_resultado'],
                'tipo_medidor'              => $radar['tipo_medidor'],
                'faixas'                    => $radar['_faixas'],
            ];

            $rawJson = json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $radar['faixas_json'] = $faixasJson;
            $radar['raw_data']    = $rawJson;
            $radar['row_hash']    = hash('sha256', $rawJson);
        }
        unset($radar);

        return $radarMap;
    }

    // ════════════════════════════════════════════════════════════
    // Diff incremental por lote
    // ════════════════════════════════════════════════════════════

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
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        // ── Merge automático de radares manuais ──────────────────
        // Feito FORA da transação DBAL para usar o EntityManager sem
        // conflito de transações aninhadas.
        $this->mergeRadaresManuais($changed);
    }

    // ════════════════════════════════════════════════════════════
    // Merge: vincula RadarManual pendente ao radar_medidor oficial
    // ════════════════════════════════════════════════════════════

    private function mergeRadaresManuais(array $changed): void
    {
        if ($changed === []) {
            return;
        }

        $identityHashes = array_column($changed, 'identity_hash');

        // Carrega todos os RadarManual pendentes que batem com este lote
        $manuaisPendentes = $this->radarManualRepo
            ->findPendentesByIdentityHash($identityHashes);

        if ($manuaisPendentes === []) {
            return;
        }

        $now = new \DateTimeImmutable();

        foreach ($changed as $row) {
            $manual = $manuaisPendentes[$row['identity_hash']] ?? null;

            if ($manual === null) {
                continue;
            }

            // Busca o ID do radar_medidor que acabou de ser inserido/atualizado
            $radarId = $row['_db_id'] ?? $this->findIdByRowHash($row['row_hash']);

            if ($radarId === null) {
                continue;
            }

            $manual->setStatus(RadarManual::STATUS_MESCLADO);
            $manual->setRadarMedidorId($radarId);
            $manual->setMescladoEm($now);

            $this->countMerged++;
        }

        if ($this->countMerged > 0) {
            $this->em->flush();
        }
    }

    // ════════════════════════════════════════════════════════════
    // Queries
    // ════════════════════════════════════════════════════════════

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
            $map[$row['identity_hash']] = [
                'id'       => (int) $row['id'],
                'row_hash' => $row['row_hash'],
            ];
        }

        return $map;
    }

    private function insertBatch(array $rows): void
    {
        $cols      = self::INSERT_COLS;
        $rowHolder = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $params    = [];

        foreach ($rows as $row) {
            foreach ($cols as $col) {
                $params[] = $row[$col] ?? null;
            }
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT IGNORE INTO radar_medidor (%s) VALUES %s',
                implode(',', $cols),
                implode(',', array_fill(0, count($rows), $rowHolder))
            ),
            $params
        );
    }

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

    // ════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════

    private function buildIdentityHash(string $uf, ?string $local, ?string $tipo): string
    {
        return hash('sha256', implode('|', [
            strtoupper($uf),
            strtoupper(trim((string) $local)),
            strtoupper(trim((string) $tipo)),
        ]));
    }

    /**
     * Calcula a data de verificação efetiva (dd/mm/aaaa).
     *
     * Regra:
     *   1. Se $dataVerif não é vazia → retorna ela.
     *   2. Se $dataValid não é vazia → retorna dataValid - 1 ano.
     *   3. Ambas nulas/vazias → retorna null.
     */
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

    private function normalizeKey(string $col): string
    {
        $col = mb_strtolower(trim($col), 'UTF-8');
        $col = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $col) ?: $col;
        $col = preg_replace('/[^a-z0-9]/', '', $col) ?? $col;

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
