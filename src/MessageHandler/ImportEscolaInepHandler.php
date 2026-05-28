<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportEscolaInepMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Importa o CSV de escolas INEP publicado no Google Sheets.
 *
 * Estratégia anti-travamento (212k+ registros):
 *  - Download via cURL para arquivo temporário (sem alocar na RAM)
 *  - Parse via fgetcsv() linha a linha — O(1) de memória
 *  - Batches de BATCH_SIZE registros → transações pequenas
 *  - Diff incremental: row_hash (qualquer mudança) + identity_hash (código INEP)
 *
 * CSV formato:
 *  - Separador: vírgula (,)
 *  - Encoding: UTF-8 (Google Sheets exporta UTF-8)
 *  - Primeira linha: cabeçalho
 *
 * Mapeamento de colunas (cabeçalho original → campo da tabela):
 *  Restrição de Atendimento → restricao_atendimento
 *  Escola                   → escola
 *  Código INEP              → codigo_inep  ← chave de identidade
 *  UF                       → uf
 *  Município                → municipio
 *  Localização              → localizacao
 *  Localidade Diferenciada  → localidade_diferenciada
 *  Categoria Administrativa → categoria_administrativa
 *  Endereço                 → endereco
 *  Telefone                 → telefone
 *  Dependência Administrativa → dependencia_administrativa
 *  Categoria Escola Privada → categoria_escola_privada
 *  Conveniada Poder Público → conveniada
 *  Regulamentação pelo Conselho de Educação → regulamentacao
 *  Porte da Escola          → porte
 *  Etapas e Modalidade de Ensino Oferecidas → etapas_ensino
 *  Outras Ofertas Educacionais → outras_ofertas
 *  Latitude                 → latitude
 *  Longitude                → longitude
 */
#[AsMessageHandler]
final class ImportEscolaInepHandler
{
    private const BATCH_SIZE = 200;

    private const TABLE = 'escola_inep';

    private const INSERT_COLS = [
        'restricao_atendimento', 'escola', 'codigo_inep',
        'uf', 'municipio', 'localizacao', 'localidade_diferenciada',
        'categoria_administrativa', 'endereco', 'telefone',
        'dependencia_administrativa', 'categoria_escola_privada',
        'conveniada', 'regulamentacao', 'porte',
        'etapas_ensino', 'outras_ofertas',
        'latitude', 'longitude',
        'row_hash', 'identity_hash', 'raw_data',
        'imported_at', 'updated_at',
    ];

    private const UPDATE_COLS = [
        'restricao_atendimento', 'escola',
        'uf', 'municipio', 'localizacao', 'localidade_diferenciada',
        'categoria_administrativa', 'endereco', 'telefone',
        'dependencia_administrativa', 'categoria_escola_privada',
        'conveniada', 'regulamentacao', 'porte',
        'etapas_ensino', 'outras_ofertas',
        'latitude', 'longitude',
        'row_hash', 'identity_hash', 'raw_data',
        'updated_at',
    ];

    /**
     * Mapeamento: cabeçalho normalizado do CSV → chave interna
     * Permite que o CSV mude sem quebrar o handler.
     */
    private const COL_MAP = [
        'restrição de atendimento'                     => 'restricao_atendimento',
        'restricao de atendimento'                     => 'restricao_atendimento',
        'escola'                                       => 'escola',
        'código inep'                                  => 'codigo_inep',
        'codigo inep'                                  => 'codigo_inep',
        'uf'                                           => 'uf',
        'município'                                    => 'municipio',
        'municipio'                                    => 'municipio',
        'localização'                                  => 'localizacao',
        'localizacao'                                  => 'localizacao',
        'localidade diferenciada'                      => 'localidade_diferenciada',
        'categoria administrativa'                     => 'categoria_administrativa',
        'endereço'                                     => 'endereco',
        'endereco'                                     => 'endereco',
        'telefone'                                     => 'telefone',
        'dependência administrativa'                   => 'dependencia_administrativa',
        'dependencia administrativa'                   => 'dependencia_administrativa',
        'categoria escola privada'                     => 'categoria_escola_privada',
        'conveniada poder público'                     => 'conveniada',
        'conveniada poder publico'                     => 'conveniada',
        'regulamentação pelo conselho de educação'     => 'regulamentacao',
        'regulamentacao pelo conselho de educacao'     => 'regulamentacao',
        'porte da escola'                              => 'porte',
        'etapas e modalidade de ensino oferecidas'     => 'etapas_ensino',
        'outras ofertas educacionais'                  => 'outras_ofertas',
        'latitude'                                     => 'latitude',
        'longitude'                                    => 'longitude',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(ImportEscolaInepMessage $message): void
    {
        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $tmpFile    = $this->downloadToTempFile($message->csvUrl);

        if ($tmpFile === null) {
            throw new \RuntimeException('Falha ao baixar o CSV: ' . $message->csvUrl);
        }

        try {
            $this->processFile($tmpFile, $importedAt);
        } finally {
            @unlink($tmpFile);
        }
    }

    // -------------------------------------------------------------------------
    // Download via cURL → arquivo temporário (sem alocar na RAM)
    // -------------------------------------------------------------------------

    private function downloadToTempFile(string $url): ?string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'escola_inep_');

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
            CURLOPT_TIMEOUT        => 300,  // 5 min — arquivo grande
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

        if (!$ok || $errCode !== 0 || filesize($tmpPath) < 100) {
            @unlink($tmpPath);
            throw new \RuntimeException("cURL erro {$errCode}: {$errMsg}");
        }

        return $tmpPath;
    }

    // -------------------------------------------------------------------------
    // Parse do CSV linha a linha — O(1) de memória
    // -------------------------------------------------------------------------

    private function processFile(string $path, string $importedAt): void
    {
        $fh = fopen($path, 'rb');

        if ($fh === false) {
            throw new \RuntimeException('Não foi possível abrir o arquivo temporário.');
        }

        $header    = null;
        $keyMap    = [];   // posição → chave interna
        $batch     = [];
        $lineNum   = 0;

        while (!feof($fh)) {
            $raw = fgetcsv($fh, 0, ',', '"', '\\');

            if ($raw === false) {
                continue;
            }

            $lineNum++;

            if ($header === null) {
                // Remove BOM UTF-8 se houver na primeira célula
                $raw[0] = ltrim($raw[0], "\xEF\xBB\xBF");

                $header = $raw;

                foreach ($header as $pos => $col) {
                    $normalized = strtolower(trim($col));
                    $keyMap[$pos] = self::COL_MAP[$normalized] ?? null;
                }

                continue;
            }

            if (count($raw) !== count($header)) {
                continue;
            }

            $assoc = [];
            foreach ($raw as $pos => $val) {
                $key = $keyMap[$pos] ?? null;
                if ($key !== null) {
                    $assoc[$key] = $val === '' ? null : trim($val);
                }
            }

            if (empty($assoc['codigo_inep'])) {
                continue;
            }

            $batch[] = $this->buildRow($assoc, $importedAt);

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
    // Mapeamento de linha normalizada → array de colunas para o banco
    // -------------------------------------------------------------------------

    private function buildRow(array $row, string $importedAt): array
    {
        $rawJson = json_encode(
            array_filter($row, static fn ($v) => $v !== null && $v !== ''),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return [
            'restricao_atendimento'    => $this->str($row['restricao_atendimento']    ?? null),
            'escola'                   => $this->str($row['escola']                   ?? null),
            'codigo_inep'              => $this->str($row['codigo_inep']              ?? null),
            'uf'                       => $this->str($row['uf']                       ?? null),
            'municipio'                => $this->str($row['municipio']                ?? null),
            'localizacao'              => $this->str($row['localizacao']              ?? null),
            'localidade_diferenciada'  => $this->str($row['localidade_diferenciada'] ?? null),
            'categoria_administrativa' => $this->str($row['categoria_administrativa']?? null),
            'endereco'                 => $this->str($row['endereco']                 ?? null),
            'telefone'                 => $this->str($row['telefone']                 ?? null),
            'dependencia_administrativa' => $this->str($row['dependencia_administrativa'] ?? null),
            'categoria_escola_privada' => $this->str($row['categoria_escola_privada']?? null),
            'conveniada'               => $this->str($row['conveniada']               ?? null),
            'regulamentacao'           => $this->str($row['regulamentacao']           ?? null),
            'porte'                    => $this->str($row['porte']                    ?? null),
            'etapas_ensino'            => $this->str($row['etapas_ensino']            ?? null),
            'outras_ofertas'           => $this->str($row['outras_ofertas']           ?? null),
            'latitude'                 => $this->str($row['latitude']                 ?? null),
            'longitude'                => $this->str($row['longitude']                ?? null),
            'row_hash'                 => hash('sha256', (string) $rawJson),
            'identity_hash'            => hash('sha256', (string) ($row['codigo_inep'] ?? '')),
            'raw_data'                 => $rawJson,
            'imported_at'              => $importedAt,
            'updated_at'               => $importedAt,
        ];
    }

    // -------------------------------------------------------------------------
    // Diff incremental por lote
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

    /** @return string[] */
    private function fetchExistingRowHashes(array $rowHashes): array
    {
        if ($rowHashes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($rowHashes), '?'));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT row_hash FROM " . self::TABLE . " WHERE row_hash IN ({$placeholders})",
            $rowHashes
        );

        return array_column($rows, 'row_hash');
    }

    /** @return array<string, array{id: int}> */
    private function fetchExistingByIdentity(array $identityHashes): array
    {
        if ($identityHashes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($identityHashes), '?'));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, identity_hash FROM " . self::TABLE . " WHERE identity_hash IN ({$placeholders})",
            $identityHashes
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['identity_hash']] = ['id' => (int) $row['id']];
        }

        return $map;
    }

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
            'INSERT INTO %s (%s) VALUES %s',
            self::TABLE,
            implode(', ', self::INSERT_COLS),
            implode(', ', $placeholders)
        );

        $this->connection->executeStatement($sql, $params, $types);
    }

    private function updateRow(array $row): void
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
            'UPDATE %s SET %s WHERE id = :_id',
            self::TABLE,
            implode(', ', $setParts)
        );

        $this->connection->executeStatement($sql, $params, $types);
    }

    private function str(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }
}
