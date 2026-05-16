<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportRadarMedidoresMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Baixa o JSON de medidores RBMLQ de um estado e faz upsert na tabela radar_medidor.
 *
 * Estratégia:
 *   - row_hash UNIQUE KEY → INSERT ON DUPLICATE KEY UPDATE
 *   - Linha idêntica à anterior → zero escrita no banco
 *   - Dado alterado → atualiza campos + updated_at
 *   - Novo registro → INSERT normal
 */
#[AsMessageHandler]
final class ImportRadarMedidoresHandler
{
    private const BATCH_SIZE = 200;

    /**
     * Mapeamento chave-JSON → coluna do banco.
     * O handler tenta cada chave exata, sem underlines e em camelCase.
     */
    private const FIELD_MAP = [
        'municipio'          => 'municipio',
        'logradouro'         => 'logradouro',
        'cep'                => 'cep',
        'nome_empresa'       => 'nome_empresa',
        'cnpj_empresa'       => 'cnpj_empresa',
        'tipo_medidor'       => 'tipo_medidor',
        'marca_medidor'      => 'marca_medidor',
        'modelo_medidor'     => 'modelo_medidor',
        'numero_serie'       => 'numero_serie',
        'capacidade'         => 'capacidade',
        'situacao'           => 'situacao',
        'data_verificacao'   => 'data_verificacao',
        'data_validade'      => 'data_validade',
        'data_lacre'         => 'data_lacre',
        'lacre'              => 'lacre',
        'numero_certificado' => 'numero_certificado',
        'orgao_verificador'  => 'orgao_verificador',
        'latitude'           => 'latitude',
        'longitude'          => 'longitude',
    ];

    private const INSERT_COLUMNS = [
        'uf',
        'municipio',
        'logradouro',
        'cep',
        'nome_empresa',
        'cnpj_empresa',
        'tipo_medidor',
        'marca_medidor',
        'modelo_medidor',
        'numero_serie',
        'capacidade',
        'situacao',
        'data_verificacao',
        'data_validade',
        'data_lacre',
        'lacre',
        'numero_certificado',
        'orgao_verificador',
        'latitude',
        'longitude',
        'row_hash',
        'identity_hash',
        'raw_data',
        'imported_at',
        'updated_at',
    ];

    private const UPDATE_COLUMNS = [
        'uf',
        'municipio',
        'logradouro',
        'cep',
        'nome_empresa',
        'cnpj_empresa',
        'tipo_medidor',
        'marca_medidor',
        'modelo_medidor',
        'numero_serie',
        'capacidade',
        'situacao',
        'data_verificacao',
        'data_validade',
        'data_lacre',
        'lacre',
        'numero_certificado',
        'orgao_verificador',
        'latitude',
        'longitude',
        'identity_hash',
        'raw_data',
        'updated_at',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(ImportRadarMedidoresMessage $message): void
    {
        $uf   = strtoupper($message->uf);
        $json = $this->fetchJson($message->getUrl());

        $items = match (true) {
            isset($json['data'])    => $json['data'],
            isset($json['items'])   => $json['items'],
            isset($json['results']) => $json['results'],
            array_is_list($json)    => $json,
            default                 => [],
        };

        if ($items === []) {
            return;
        }

        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $batch      = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $batch[] = $this->mapItem($item, $uf, $importedAt);

            if (count($batch) >= self::BATCH_SIZE) {
                $this->upsertBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->upsertBatch($batch);
        }
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    private function fetchJson(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout'       => 30,
                'ignore_errors' => true,
                'header'        => "Accept: application/json\r\nUser-Agent: ToolboxWaze/1.0\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return \is_array($data) ? $data : [];
    }

    // -------------------------------------------------------------------------
    // Mapeamento
    // -------------------------------------------------------------------------

    private function mapItem(array $item, string $uf, string $importedAt): array
    {
        // Normaliza TODAS as chaves para minúsculo sem underline para lookup flexível
        $flat = $this->flattenItem($item);

        $row = ['uf' => $uf];

        foreach (self::FIELD_MAP as $jsonKey => $colKey) {
            $row[$colKey] = $flat[$jsonKey]
                ?? $flat[str_replace('_', '', $jsonKey)]
                ?? $flat[$this->toCamel($jsonKey)]
                ?? null;
        }

        // Latitude e longitude: podem vir como objeto/array {lat, lng} ou campo separado
        if ($row['latitude'] === null && isset($item['localizacao'])) {
            $row['latitude']  = $this->extractScalar($item['localizacao'], ['lat', 'latitude']);
            $row['longitude'] = $this->extractScalar($item['localizacao'], ['lng', 'lon', 'longitude']);
        }

        $row['row_hash']      = $this->buildRowHash($flat, $uf);
        $row['identity_hash'] = $this->buildIdentityHash($flat, $uf);
        // raw_data: JSON string do item original (nunca array)
        $row['raw_data']      = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row['imported_at']   = $importedAt;
        $row['updated_at']    = $importedAt;

        return $row;
    }

    /**
     * Transforma o item em array plano chave=string, valor=string|null.
     * Campos que são arrays aninhados são serializados como JSON string
     * (evita o "Array to string conversion").
     */
    private function flattenItem(array $item): array
    {
        $flat = [];

        foreach ($item as $k => $v) {
            $key        = strtolower((string) $k);
            $flat[$key] = $this->scalarize($v);

            // Tambem indexa sem underlines e em camelCase para lookup flexível
            $keyNoUnderscore   = str_replace('_', '', $key);
            $flat[$keyNoUnderscore] = $flat[$key];
        }

        return $flat;
    }

    /**
     * Converte qualquer valor para string|null seguro para o banco.
     * Arrays e objetos são codificados como JSON string.
     */
    private function scalarize(mixed $value): ?string
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        if (\is_array($value) || \is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return ($encoded === false || $encoded === 'null' || $encoded === '[]' || $encoded === '{}') ? null : $encoded;
        }

        $str = trim((string) $value);

        if ($str !== '' && !mb_check_encoding($str, 'UTF-8')) {
            $str = mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
        }

        return $str === '' ? null : $str;
    }

    /**
     * Extrai um valor escalar de um array/objeto aninhado, tentando múltiplas chaves.
     */
    private function extractScalar(mixed $source, array $keys): ?string
    {
        if (!\is_array($source)) {
            return null;
        }

        foreach ($keys as $key) {
            if (isset($source[$key])) {
                return $this->scalarize($source[$key]);
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Upsert
    // -------------------------------------------------------------------------

    private function upsertBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $placeholders = [];
        $params       = [];
        $types        = [];

        foreach ($rows as $i => $row) {
            $rowPlaceholders = [];

            foreach (self::INSERT_COLUMNS as $col) {
                $key               = $col . '_' . $i;
                $rowPlaceholders[] = ':' . $key;
                $params[$key]      = $row[$col] ?? null;
                $types[$key]       = ParameterType::STRING;
            }

            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $updateParts = [];
        foreach (self::UPDATE_COLUMNS as $col) {
            $updateParts[] = sprintf('%s = VALUES(%s)', $col, $col);
        }

        $sql = sprintf(
            'INSERT INTO radar_medidor (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
            implode(', ', self::INSERT_COLUMNS),
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
    // Hashes
    // -------------------------------------------------------------------------

    private function buildRowHash(array $flat, string $uf): string
    {
        $payload = array_merge(['_uf' => $uf], $flat);
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function buildIdentityHash(array $flat, string $uf): string
    {
        $parts = [
            strtoupper(trim((string) ($flat['numero_serie'] ?? $flat['numeroserie'] ?? ''))),
            strtoupper($uf),
            strtoupper(trim((string) ($flat['cnpj_empresa'] ?? $flat['cnpjempresa'] ?? ''))),
        ];

        return hash('sha256', implode('|', $parts));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function toCamel(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));
    }
}
