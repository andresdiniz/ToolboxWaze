<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportFuelResellersMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lê o CSV da ANP em streaming (linha a linha) e insere no banco via DBAL nativo
 * usando INSERT em lotes para máxima performance sem estourar memória.
 */
#[AsMessageHandler]
final class ImportFuelResellersHandler
{
    /**
     * Quantidade de linhas por lote antes de fazer o INSERT.
     * Ajuste conforme o tamanho médio das linhas e o max_allowed_packet do MySQL.
     */
    private const BATCH_SIZE = 1000;

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

            $header      = $this->normalizeHeader($header);
            $importedAt  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $batch       = [];
            $totalRows   = 0;
            $skippedRows = 0;

            foreach ($this->streamRows($handle, $header) as $data) {
                $row = $this->mapToColumns($data, $importedAt);

                if ($row === null) {
                    ++$skippedRows;
                    continue;
                }

                $batch[] = $row;
                ++$totalRows;

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->insertBatch($batch);
                    $batch = [];
                    gc_collect_cycles();
                }
            }

            if ($batch !== []) {
                $this->insertBatch($batch);
            }

        } finally {
            fclose($handle);
        }
    }

    /**
     * Generator que produz arrays normalizados linha a linha — nunca acumula tudo em memória.
     *
     * @param resource $handle
     * @return \Generator<array<string, string|null>>
     */
    private function streamRows($handle, array $header): \Generator
    {
        $headerCount = count($header);

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $row = $this->normalizeRowColumnCount($row, $headerCount);

            $data = array_combine($header, $row);

            if ($data === false) {
                continue;
            }

            yield $this->normalizeData($data);
        }
    }

    /**
     * Monta o array de colunas pronto para o INSERT.
     * Retorna null se a linha não tiver ao menos CNPJ ou código ISIMP.
     *
     * @param array<string, string|null> $data
     * @return array<string, string|null>|null
     */
    private function mapToColumns(array $data, string $importedAt): ?array
    {
        // Linhas completamente sem identificação são descartadas
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
        ];
    }

    /**
     * Executa INSERT em lote usando parâmetros nomeados via DBAL.
     * Usa transação por lote para garantir atomicidade.
     *
     * @param list<array<string, string|null>> $rows
     */
    private function insertBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $placeholders = [];
        $params       = [];
        $types        = [];

        foreach ($rows as $i => $row) {
            $rowPlaceholders = [];

            foreach (self::COLUMNS as $col) {
                $key                = $col . '_' . $i;
                $rowPlaceholders[]  = ':' . $key;
                $params[$key]       = $row[$col] ?? null;
                $types[$key]        = \PDO::PARAM_STR;
            }

            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $sql = sprintf(
            'INSERT INTO fuel_reseller_raw (%s) VALUES %s',
            implode(', ', self::COLUMNS),
            implode(', ', $placeholders)
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

    /**
     * Hash completo da linha — SHA-256 de todos os campos.
     * Muda se qualquer dado da linha mudar entre importações.
     */
    private function buildRowHash(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Hash de identidade — SHA-256 de: razao_social + endereco + cep + uf + municipio.
     * Permanece igual mesmo que CNPJ ou bandeira mudem.
     * Base para detectar, no futuro, postos que trocaram de CNPJ sem mudar de local.
     */
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
