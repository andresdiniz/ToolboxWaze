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
 * Estratégia idêntica ao ImportFuelResellersHandler:
 *   - row_hash UNIQUE KEY → INSERT ON DUPLICATE KEY UPDATE
 *   - Linha idêntica à anterior → zero escrita no banco
 *   - Dado alterado → atualiza campos + updated_at
 *   - Novo registro → INSERT normal
 */
#[AsMessageHandler]
final class ImportRadarMedidoresHandler
{
    private const BATCH_SIZE = 200;

    /** Mapeamento chave-JSON → coluna do banco */
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

    /** Colunas atualizadas no ON DUPLICATE KEY UPDATE (exclui row_hash e imported_at) */
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
        $uf  = strtoupper($message->uf);
        $url = $message->getUrl();

        $json = $this->fetchJson($url);

        // API pode retornar array direto ou objeto com chave de dados
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

            $row     = $this->mapItem($item, $uf, $importedAt);
            $batch[] = $row;

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

        if ($raw === false || $raw === '') {
            // Estado sem dados ou endpoint fora do ar — não é erro fatal
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
        // Normaliza chaves do JSON para snake_case minúsculo
        $normalized = [];
        foreach ($item as $k => $v) {
            $key              = strtolower((string) $k);
            $normalized[$key] = $this->normalizeValue($v);
        }

        $row = ['uf' => $uf];

        foreach (self::FIELD_MAP as $jsonKey => $colKey) {
            // Tenta a chave exata e variações comuns (sem underline, camelCase)
            $row[$colKey] = $normalized[$jsonKey]
                ?? $normalized[str_replace('_', '', $jsonKey)]
                ?? $normalized[$this->toCamel($jsonKey)]
                ?? null;
        }

        $row['row_hash']      = $this->buildRowHash($normalized, $uf);
        $row['identity_hash'] = $this->buildIdentityHash($normalized, $uf);
        $row['raw_data']      = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row['imported_at']   = $importedAt;
        $row['updated_at']    = $importedAt;

        return $row;
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

    private function buildRowHash(array $data, string $uf): string
    {
        $payload = array_merge(['_uf' => $uf], $data);
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function buildIdentityHash(array $data, string $uf): string
    {
        // Número de série + UF identificam unicamente um medidor físico
        $parts = [
            strtoupper(trim((string) ($data['numero_serie'] ?? $data['numeroserie'] ?? ''))),
            strtoupper($uf),
            strtoupper(trim((string) ($data['cnpj_empresa'] ?? $data['cnpjempresa'] ?? ''))),
        ];

        return hash('sha256', implode('|', $parts));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $str = trim((string) $value);

        if ($str !== '' && !mb_check_encoding($str, 'UTF-8')) {
            $str = mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
        }

        return $str === '' ? null : $str;
    }

    private function toCamel(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));
    }
}
