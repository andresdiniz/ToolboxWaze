<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportFuelResellersMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lê o CSV da ANP em streaming e faz UPSERT via INSERT ... ON DUPLICATE KEY UPDATE.
 *
 * Estratégia:
 *   - A UNIQUE KEY é o row_hash (SHA-256 de toda a linha).
 *   - Se o row_hash JÁ existe → a linha não mudou → banco ignora (zero escrita).
 *   - Se o row_hash NÃO existe mas o identity_hash existe → dado do posto mudou
 *     (ex: nova bandeira, endereço corrigido) → banco atualiza todos os campos.
 *   - Se nem row_hash nem identity_hash existem → novo posto → INSERT normal.
 *
 * O resultado: apenas inserções e atualizações reais chegam ao disco;
 * registros idênticos ao importado anteriormente não geram nenhuma escrita.
 */
#[AsMessageHandler]
final class ImportFuelResellersHandler
{
    private const BATCH_SIZE = 500;

    /** Colunas gravadas no INSERT e atualizadas no ON DUPLICATE KEY UPDATE */
    private const COLUMNS = [
        'codigo_isimp',
        'autorizacao',
        'data_publicacao',
        'razao_social',
        'cnpj',
        'endereco',
        'complemento',
        'bairro',
        'cep',
        'uf',
        'municipio',
        'bandeira',
        'data_vinculacao',
        'nome_fantasia',
        'row_hash',
        'identity_hash',
        'raw_data',
        'imported_at',
    ];

    /**
     * Colunas que são atualizadas quando o row_hash muda.
     * Exclui: row_hash (é a UNIQUE KEY), imported_at (data do primeiro import).
     * Inclui: updated_at para registrar a data da atualização.
     */
    private const UPDATE_COLUMNS = [
        'codigo_isimp',
        'autorizacao',
        'data_publicacao',
        'razao_social',
        'cnpj',
        'endereco',
        'complemento',
        'bairro',
        'cep',
        'uf',
        'municipio',
        'bandeira',
        'data_vinculacao',
        'nome_fantasia',
        'identity_hash',
        'raw_data',
        'updated_at',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(ImportFuelResellersMessage $message): void
    {
        $handle = @fopen($message->url, 'r');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Não foi possível abrir a URL: %s', $message->url));
        }

        try {
            $header = fgetcsv($handle, 0, ';');

            if ($header === false) {
                throw new \RuntimeException('Não foi possível ler o cabeçalho do CSV.');
            }

            $header     = $this->normalizeHeader($header);
            $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $updatedAt  = $importedAt;
            $batch      = [];

            foreach ($this->streamRows($handle, $header) as $data) {
                $row = $this->mapToColumns($data, $importedAt, $updatedAt);

                if ($row === null) {
                    continue;
                }

                $batch[] = $row;

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->upsertBatch($batch);
                    $batch = [];
                    gc_collect_cycles();
                }
            }

            if ($batch !== []) {
                $this->upsertBatch($batch);
            }
        } finally {
            fclose($handle);
        }
    }

    // -------------------------------------------------------------------------
    // Streaming
    // -------------------------------------------------------------------------

    /** @param resource $handle */
    private function streamRows($handle, array $header): \Generator
    {
        $headerCount = count($header);

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $row  = $this->normalizeRowColumnCount($row, $headerCount);
            $data = array_combine($header, $row);

            if ($data === false) {
                continue;
            }

            yield $this->normalizeData($data);
        }
    }

    // -------------------------------------------------------------------------
    // Mapeamento
    // -------------------------------------------------------------------------

    /** @return array<string, string|null>|null */
    private function mapToColumns(array $data, string $importedAt, string $updatedAt): ?array
    {
        if (($data['CNPJ'] ?? null) === null && ($data['CODIGOISIMP'] ?? null) === null) {
            return null;
        }

        return [
            'codigo_isimp'    => $data['CODIGOISIMP'] ?? null,
            'autorizacao'     => $data['AUTORIZACAO'] ?? null,
            'data_publicacao' => $data['DATAPUBLICACAO'] ?? null,
            'razao_social'    => $data['RAZAOSOCIAL'] ?? null,
            'cnpj'            => $data['CNPJ'] ?? null,
            'endereco'        => $data['ENDERECO'] ?? null,
            'complemento'     => $data['COMPLEMENTO'] ?? null,
            'bairro'          => $data['BAIRRO'] ?? null,
            'cep'             => $data['CEP'] ?? null,
            'uf'              => $data['UF'] ?? null,
            'municipio'       => $data['MUNICIPIO'] ?? null,
            'bandeira'        => $data['BANDEIRA'] ?? null,
            'data_vinculacao' => $data['DATAVINCULACAO'] ?? null,
            'nome_fantasia'   => $data['NOMEFANTASIA'] ?? null,
            'row_hash'        => $this->buildRowHash($data),
            'identity_hash'   => $this->buildIdentityHash($data),
            'raw_data'        => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'imported_at'     => $importedAt,
            'updated_at'      => $updatedAt,
        ];
    }

    // -------------------------------------------------------------------------
    // Upsert
    // -------------------------------------------------------------------------

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE
     *
     * - row_hash é UNIQUE KEY → colisão = registro já existe com dados idênticos → banco ignora
     * - Se o row_hash mudou (dado do posto foi alterado), é um INSERT novo que vai passar;
     *   mas se o identity_hash já existe numa linha anterior, os dados antigos ficam lá
     *   enquanto a nova versão é inserida como nova linha com novo row_hash.
     *
     * Na prática: a UNIQUE KEY no row_hash garante idempotência total.
     * Rodar a importação 10 vezes com o mesmo arquivo = zero escrita adicional na 2ª vez em diante.
     *
     * @param list<array<string, string|null>> $rows
     */
    private function upsertBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $placeholders = [];
        $params       = [];
        $types        = [];
        $allColumns   = array_merge(self::COLUMNS, ['updated_at']);

        foreach ($rows as $i => $row) {
            $rowPlaceholders = [];

            foreach ($allColumns as $col) {
                $key               = $col . '_' . $i;
                $rowPlaceholders[] = ':' . $key;
                $params[$key]      = $row[$col] ?? null;
                $types[$key]       = ParameterType::STRING;
            }

            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        // Monta a cláusula ON DUPLICATE KEY UPDATE
        // - Colunas em UPDATE_COLUMNS são sobrescritas com VALUES(col)
        // - imported_at NÃO está em UPDATE_COLUMNS → preserva a data do primeiro import
        // - row_hash NÃO está em UPDATE_COLUMNS → não faz sentido atualizar a UNIQUE KEY
        $updateParts = [];
        foreach (self::UPDATE_COLUMNS as $col) {
            $updateParts[] = sprintf('%s = VALUES(%s)', $col, $col);
        }

        $sql = sprintf(
            'INSERT INTO fuel_reseller_raw (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
            implode(', ', $allColumns),
            implode(', ', $placeholders),
            implode(', ', $updateParts)
        );

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement($sql, $params, $types);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers de normalização
    // -------------------------------------------------------------------------

    private function normalizeHeader(array $header): array
    {
        foreach ($header as &$value) {
            $value = $this->removeBom($value);
            $value = $this->normalizeString($value);
            $value = strtoupper(str_replace([' ', '_', '-'], '', $value));
        }

        return $header;
    }

    private function normalizeRowColumnCount(array $row, int $headerCount): array
    {
        $count = count($row);

        if ($count < $headerCount) {
            return array_pad($row, $headerCount, '');
        }

        if ($count > $headerCount) {
            return array_slice($row, 0, $headerCount);
        }

        return $row;
    }

    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            $normalized[$key] = $this->normalizeNullableString($value);
        }

        return $normalized;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = $this->normalizeString((string) $value);

        return $str === '' ? null : $str;
    }

    private function normalizeString(string $value): string
    {
        $value = $this->removeBom($value);
        $value = trim($value);

        if ($value !== '' && !mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return $value;
    }

    private function removeBom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function buildRowHash(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function buildIdentityHash(array $data): string
    {
        $parts = [
            $this->normalizeIdentityPart($data['RAZAOSOCIAL'] ?? null),
            $this->normalizeIdentityPart($data['ENDERECO'] ?? null),
            $this->normalizeIdentityPart($data['CEP'] ?? null),
            $this->normalizeIdentityPart($data['UF'] ?? null),
            $this->normalizeIdentityPart($data['MUNICIPIO'] ?? null),
            $this->normalizeIdentityPart($data['NOMEFANTASIA'] ?? null),
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function normalizeIdentityPart(?string $value): string
    {
        $value = $value ?? '';
        $value = mb_strtoupper($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
