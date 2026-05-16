<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportRadarMedidoresMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Baixa o JSON RBMLQ de um estado e faz upsert na tabela radar_medidor.
 *
 * Estrutura real do JSON:
 * [
 *   {
 *     "SiglaUf", "Estado", "Municipio", "LocalVerificacao",
 *     "DataUltimaVerificacao", "DataValidade", "UltimoResultado", "TipoMedidor",
 *     "Faixas":    [{ NumeroFaixa, NumeroInmetro, NumeroSerie, Sentido, VelocidadeNominal }],
 *     "Historico": [{ NumeroCertificado, NumeroEnsaio, Ano, DataLaudo, DataValidade, TipoServico, Resultado }],
 *     "Proprietario": { Nome, Municipio, Estado }
 *   }
 * ]
 *
 * Estratégia de upsert:
 *   - row_hash (SHA-256 do JSON completo) é UNIQUE KEY
 *   - Se row_hash já existe → dado não mudou → zero escrita
 *   - Se row_hash mudou → atualiza o radar + apaga e recria faixas/histórico
 *   - Se novo → insere radar + faixas + histórico
 */
#[AsMessageHandler]
final class ImportRadarMedidoresHandler
{
    private const BATCH_SIZE = 100;

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
        // row_hash e imported_at NÃO entram no UPDATE
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(ImportRadarMedidoresMessage $message): void
    {
        $uf    = strtoupper($message->uf);
        $items = $this->fetchItems($message->getUrl());

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
                $this->processBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->processBatch($batch);
        }
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    private function fetchItems(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout'       => 30,
                'ignore_errors' => true,
                'header'        => "Accept: application/json\r\nUser-Agent: ToolboxWaze/1.0\r\n",
            ],
        ]);

        $raw  = @file_get_contents($url, false, $context);
        $data = $raw ? json_decode($raw, true) : null;

        if (!\is_array($data)) {
            return [];
        }

        // API retorna lista direta ou objeto com chave de dados
        return match (true) {
            isset($data['data'])    => $data['data'],
            isset($data['items'])   => $data['items'],
            isset($data['results']) => $data['results'],
            array_is_list($data)    => $data,
            default                 => [],
        };
    }

    // -------------------------------------------------------------------------
    // Mapeamento
    // -------------------------------------------------------------------------

    private function mapItem(array $item, string $uf, string $importedAt): array
    {
        $prop = \is_array($item['Proprietario'] ?? null) ? $item['Proprietario'] : [];

        // Faixas e Histórico são arrays — gravados como JSON string na coluna
        $faixas    = \is_array($item['Faixas']    ?? null) ? $item['Faixas']    : [];
        $historico = \is_array($item['Historico'] ?? null) ? $item['Historico'] : [];

        $rawJson      = json_encode($item,      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $faixasJson   = json_encode($faixas,    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $historicoJson = json_encode($historico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $rowHash      = hash('sha256', $rawJson);
        $identityHash = $this->buildIdentityHash($item, $uf);

        return [
            // Campos simples
            'sigla_uf'                => $uf,
            'estado'                  => $this->str($item['Estado'] ?? null),
            'municipio'               => $this->str($item['Municipio'] ?? null),
            'local_verificacao'       => $this->str($item['LocalVerificacao'] ?? null),
            'data_ultima_verificacao' => $this->str($item['DataUltimaVerificacao'] ?? null),
            'data_validade'           => $this->str($item['DataValidade'] ?? null),
            'ultimo_resultado'        => $this->str($item['UltimoResultado'] ?? null),
            'tipo_medidor'            => $this->str($item['TipoMedidor'] ?? null),
            // Proprietário
            'proprietario_nome'       => $this->str($prop['Nome'] ?? null),
            'proprietario_municipio'  => $this->str($prop['Municipio'] ?? null),
            'proprietario_estado'     => $this->str($prop['Estado'] ?? null),
            // Arrays como JSON string
            'faixas_json'             => $faixasJson,
            'historico_json'          => $historicoJson,
            // Metadados
            'row_hash'                => $rowHash,
            'identity_hash'           => $identityHash,
            'raw_data'                => $rawJson,
            'imported_at'             => $importedAt,
            'updated_at'              => $importedAt,
            // Guardados separado para inserir nas tabelas relacionadas
            '_faixas'                 => $faixas,
            '_historico'              => $historico,
        ];
    }

    // -------------------------------------------------------------------------
    // Processo de lote: upsert radares + faixas + histórico
    // -------------------------------------------------------------------------

    private function processBatch(array $rows): void
    {
        $this->connection->beginTransaction();

        try {
            // 1. Upsert dos radares
            $this->upsertRadarBatch($rows);

            // 2. Para cada radar, sincroniza faixas e histórico
            foreach ($rows as $row) {
                $radarId = $this->findRadarIdByHash($row['row_hash']);

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

    private function upsertRadarBatch(array $rows): void
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

        $updateParts = [];
        foreach (self::RADAR_UPDATE_COLS as $col) {
            $updateParts[] = "{$col} = VALUES({$col})";
        }

        $sql = sprintf(
            'INSERT INTO radar_medidor (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
            implode(', ', self::RADAR_INSERT_COLS),
            implode(', ', $placeholders),
            implode(', ', $updateParts)
        );

        $this->connection->executeStatement($sql, $params, $types);
    }

    private function findRadarIdByHash(string $rowHash): ?int
    {
        $result = $this->connection->fetchOne(
            'SELECT id FROM radar_medidor WHERE row_hash = ?',
            [$rowHash]
        );

        return $result !== false ? (int) $result : null;
    }

    /** Apaga e recria as faixas do radar (são poucos registros por radar) */
    private function syncFaixas(int $radarId, array $faixas): void
    {
        $this->connection->executeStatement(
            'DELETE FROM radar_faixa WHERE radar_medidor_id = ?',
            [$radarId]
        );

        foreach ($faixas as $faixa) {
            if (!\is_array($faixa)) continue;

            $this->connection->executeStatement(
                'INSERT INTO radar_faixa
                    (radar_medidor_id, numero_faixa, numero_inmetro, numero_serie, sentido, velocidade_nominal)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $radarId,
                    $this->str($faixa['NumeroFaixa']      ?? null),
                    $this->str($faixa['NumeroInmetro']    ?? null),
                    $this->str($faixa['NumeroSerie']      ?? null),
                    $this->str($faixa['Sentido']          ?? null),
                    $this->str($faixa['VelocidadeNominal'] ?? null),
                ]
            );
        }
    }

    /** Apaga e recria o histórico do radar */
    private function syncHistorico(int $radarId, array $historico): void
    {
        $this->connection->executeStatement(
            'DELETE FROM radar_historico WHERE radar_medidor_id = ?',
            [$radarId]
        );

        foreach ($historico as $h) {
            if (!\is_array($h)) continue;

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
    // Hashes
    // -------------------------------------------------------------------------

    private function buildIdentityHash(array $item, string $uf): string
    {
        // Identifica o mesmo ponto físico mesmo que o conteúdo mude
        $parts = [
            strtoupper($uf),
            strtoupper(trim((string) ($item['LocalVerificacao'] ?? ''))),
            strtoupper(trim((string) ($item['TipoMedidor']     ?? ''))),
        ];

        return hash('sha256', implode('|', $parts));
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

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
