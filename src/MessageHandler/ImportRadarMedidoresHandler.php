<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportRadarMedidoresMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Baixa o JSON RBMLQ de um estado e faz upsert em radar_medidor / radar_faixa / radar_historico.
 *
 * Problema de memória:
 *   Estados como SC e SP têm JSONs de centenas de MB.
 *   file_get_contents() carregava tudo na RAM → OOM.
 *
 * Solução:
 *   1. Download via cURL escrevendo direto em arquivo temporário (stream, zero RAM extra).
 *   2. Parse incremental: lê o arquivo char a char contando chaves { } para extrair
 *      cada objeto completo e fazer json_decode() só nele.
 *   3. Processa em lotes de BATCH_SIZE itens para não acumular memória.
 */
#[AsMessageHandler]
final class ImportRadarMedidoresHandler
{
    private const BATCH_SIZE = 50;

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
        $uf         = strtoupper($message->uf);
        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $tmpFile    = $this->downloadToTempFile($message->getUrl());

        if ($tmpFile === null) {
            return;
        }

        try {
            $this->processFile($tmpFile, $uf, $importedAt);
        } finally {
            @unlink($tmpFile);
        }
    }

    // -------------------------------------------------------------------------
    // Download via cURL em arquivo temporário (stream, sem alocar RAM)
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
            CURLOPT_FILE           => $fp,       // escreve direto no arquivo
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'ToolboxWaze/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_FAILONERROR    => true,
        ]);

        $ok      = curl_exec($ch);
        $errCode = curl_errno($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $errCode !== 0 || filesize($tmpPath) < 3) {
            @unlink($tmpPath);
            return null;
        }

        return $tmpPath;
    }

    // -------------------------------------------------------------------------
    // Parse incremental: extrai objetos JSON {..} do arquivo um a um
    // -------------------------------------------------------------------------

    /**
     * Lê o arquivo em blocos de 64 KB, mantendo um buffer de acumulação.
     * Conta abertura/fechamento de chaves para detectar quando um objeto
     * JSON de nível raiz (depth 1 dentro do array) está completo.
     *
     * Funciona tanto para JSON formatado (multi-linha) quanto minificado.
     */
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
            $out     = ''           ; // parte já processada do buffer — descartada
            $objStart = null;

            $len = strlen($buffer);

            for ($i = 0; $i < $len; $i++) {
                $c = $buffer[$i];

                // Controle de string JSON (ignora chaves dentro de strings)
                if ($escape) {
                    $escape = false;
                    continue;
                }

                if ($c === '\\' && $inString) {
                    $escape = true;
                    continue;
                }

                if ($c === '"') {
                    $inString = !$inString;
                    continue;
                }

                if ($inString) {
                    continue;
                }

                if ($c === '{') {
                    if ($depth === 0) {
                        $objStart = $i; // marca o início do objeto
                    }
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;

                    if ($depth === 0 && $objStart !== null) {
                        // Objeto completo encontrado
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

                        // Descarta tudo até aqui do buffer
                        $out      = substr($buffer, $i + 1);
                        $objStart = null;
                    }
                }
            }

            // Mantém no buffer apenas a parte ainda não processada
            if ($objStart !== null) {
                // Objeto ainda aberto: mantém desde o início
                $buffer = substr($buffer, $objStart);
            } elseif ($out !== '') {
                $buffer = $out;
            } else {
                // Nenhum objeto encontrado neste chunk: descarta parte segura
                // (guarda os últimos 256 bytes por segurança)
                if (strlen($buffer) > 256) {
                    $buffer = substr($buffer, -256);
                }
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

        $siglaUf = strtoupper($this->str($item['SiglaUf'] ?? null) ?? $uf);

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
    // Lote: upsert + faixas + histórico
    // -------------------------------------------------------------------------

    private function processBatch(array $rows): void
    {
        $this->connection->beginTransaction();

        try {
            $this->upsertRadarBatch($rows);

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
    // Hashes
    // -------------------------------------------------------------------------

    private function buildIdentityHash(array $item, string $uf): string
    {
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
