<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa radares de uma planilha Google Sheets com 3 abas (GIDs):
 *
 *   --gid-medidores   Aba 1 — dados técnicos (Município, Local, Tipo, Faixa, Inmetro, Série, Sentido, Velocidade)
 *   --gid-verificacao Aba 2 — dados de verificação (Município, Data Verificação, Data Validade, Resultado, Local)
 *   --gid-links       Aba 3 — links Waze (Local, Link ou colunas que permitam cruzamento)
 *
 * ESTRATÉGIA DE MERGE (sem duplicar, sem perder dados):
 *
 *   Prioridade de lookup (do mais confiável ao menos confiável):
 *     1. numero_serie  (identificador único do equipamento físico)
 *     2. numero_inmetro
 *     3. identity_hash = SHA256(sigla_uf | local_verificacao_normalizado | tipo_medidor)
 *
 *   Para cada linha:
 *     - Encontrou por série/inmetro? → UPDATE apenas campos vazios + data se mais recente
 *     - Encontrou por identity_hash?  → UPDATE + preenche serie/inmetro se estavam vazios
 *     - Não encontrou?               → INSERT
 *
 * USO:
 *   php bin/console app:import-radar-multi-aba \
 *     --spreadsheet-id=SEU_SPREADSHEET_ID \
 *     --uf=MG \
 *     --gid-medidores=0 \
 *     --gid-verificacao=123456 \
 *     --gid-links=789012
 *
 *   Para importar apenas uma aba, omita as outras duas.
 *   --dry-run   Simula sem gravar no banco.
 */
#[AsCommand(
    name: 'app:import-radar-multi-aba',
    description: 'Importa radares de planilha Google Sheets com até 3 abas (medidores, verificação, links Waze)',
)]
final class ImportRadarMultiAbaCommand extends Command
{
    private const CURL_TIMEOUT = 120;
    private const BATCH_SIZE   = 200;

    // URL base de exportação CSV do Google Sheets
    private const SHEETS_URL = 'https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%s';

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    // ════════════════════════════════════════════════════════════
    // Configuração
    // ════════════════════════════════════════════════════════════

    protected function configure(): void
    {
        $this
            ->addOption('spreadsheet-id',  's', InputOption::VALUE_REQUIRED, 'ID da planilha Google Sheets')
            ->addOption('uf',              'u', InputOption::VALUE_REQUIRED, 'Sigla UF (ex: MG)', '')
            ->addOption('gid-medidores',   null, InputOption::VALUE_OPTIONAL, 'GID da aba de medidores técnicos')
            ->addOption('gid-verificacao', null, InputOption::VALUE_OPTIONAL, 'GID da aba de verificações')
            ->addOption('gid-links',       null, InputOption::VALUE_OPTIONAL, 'GID da aba de links Waze')
            ->addOption('dry-run',         null, InputOption::VALUE_NONE,     'Simula sem gravar no banco')
        ;
    }

    // ════════════════════════════════════════════════════════════
    // Execução
    // ════════════════════════════════════════════════════════════

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $spreadsheetId = trim((string) $input->getOption('spreadsheet-id'));
        $uf            = strtoupper(trim((string) $input->getOption('uf')));
        $gidMedidores  = $input->getOption('gid-medidores');
        $gidVerif      = $input->getOption('gid-verificacao');
        $gidLinks      = $input->getOption('gid-links');
        $dryRun        = (bool) $input->getOption('dry-run');

        if ($spreadsheetId === '') {
            $io->error('--spreadsheet-id é obrigatório.');
            return Command::FAILURE;
        }
        if ($gidMedidores === null && $gidVerif === null && $gidLinks === null) {
            $io->error('Informe pelo menos um GID (--gid-medidores, --gid-verificacao ou --gid-links).');
            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->warning('MODO DRY-RUN — nenhuma alteração será gravada.');
        }

        $totais = ['inseridos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'links' => 0, 'erros' => 0];

        // ── Aba 1: Medidores ──────────────────────────────────────
        if ($gidMedidores !== null) {
            $io->section("Aba medidores (GID={$gidMedidores})");
            $url  = sprintf(self::SHEETS_URL, $spreadsheetId, $gidMedidores);
            $rows = $this->downloadCsv($url, $io);
            if ($rows !== null) {
                $r = $this->processMedidores($rows, $uf, $dryRun, $io);
                $totais['inseridos']   += $r['inseridos'];
                $totais['atualizados'] += $r['atualizados'];
                $totais['sem_mudanca'] += $r['sem_mudanca'];
                $totais['erros']       += $r['erros'];
            }
        }

        // ── Aba 2: Verificações ──────────────────────────────────
        if ($gidVerif !== null) {
            $io->section("Aba verificações (GID={$gidVerif})");
            $url  = sprintf(self::SHEETS_URL, $spreadsheetId, $gidVerif);
            $rows = $this->downloadCsv($url, $io);
            if ($rows !== null) {
                $r = $this->processVerificacoes($rows, $uf, $dryRun, $io);
                $totais['atualizados'] += $r['atualizados'];
                $totais['inseridos']   += $r['inseridos'];
                $totais['sem_mudanca'] += $r['sem_mudanca'];
                $totais['erros']       += $r['erros'];
            }
        }

        // ── Aba 3: Links Waze ──────────────────────────────────────
        if ($gidLinks !== null) {
            $io->section("Aba links Waze (GID={$gidLinks})");
            $url  = sprintf(self::SHEETS_URL, $spreadsheetId, $gidLinks);
            $rows = $this->downloadCsv($url, $io);
            if ($rows !== null) {
                $r = $this->processLinks($rows, $uf, $dryRun, $io);
                $totais['links']  += $r['links'];
                $totais['erros']  += $r['erros'];
            }
        }

        $io->success(sprintf(
            'Concluído — inseridos: %d | atualizados: %d | sem mudança: %d | links: %d | erros: %d',
            $totais['inseridos'], $totais['atualizados'], $totais['sem_mudanca'], $totais['links'], $totais['erros']
        ));

        return Command::SUCCESS;
    }

    // ════════════════════════════════════════════════════════════
    // Aba 1 — Medidores técnicos
    // Colunas esperadas:
    //   Município | Data Verificação | Data Validade | Resultado |
    //   Local | Tipo | Faixa | Inmetro | Série | Sentido | Velocidade
    // ════════════════════════════════════════════════════════════

    private function processMedidores(array $rows, string $uf, bool $dryRun, SymfonyStyle $io): array
    {
        $stats      = ['inseridos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0];
        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Agrupa por (local+tipo+data_validade) para acumular faixas
        $grupos = [];
        foreach ($rows as $row) {
            $municipio   = $this->normalizeStr($row['municipio']   ?? $row['município'] ?? '');
            $local       = $this->normalizeStr($row['local']       ?? '');
            $tipo        = $this->normalizeStr($row['tipo']        ?? $row['tipodemedidor'] ?? $row['tipo_medidor'] ?? '');
            $dataVerif   = $this->parseDate($row['dataverificacao']  ?? $row['data verificação'] ?? $row['data_verificacao'] ?? '');
            $dataVal     = $this->parseDate($row['datavalidade']     ?? $row['data validade']    ?? $row['data_validade']    ?? '');
            $resultado   = $this->normalizeStr($row['resultado'] ?? '');
            $faixa       = trim($row['faixa']    ?? '');
            $inmetro     = trim($row['inmetro']  ?? $row['numeroinmetro'] ?? '');
            $serie       = trim($row['série']    ?? $row['serie']  ?? $row['numeroserie'] ?? '');
            $sentido     = $this->normalizeStr($row['sentido'] ?? '');
            $velocidade  = trim($row['velocidade'] ?? $row['velocidadenominal'] ?? '');

            if ($local === '' || $municipio === '') {
                continue;
            }

            $chave = $this->identityHash($uf, $local, $tipo);

            if (!isset($grupos[$chave])) {
                $grupos[$chave] = [
                    'sigla_uf'                  => $uf ?: null,
                    'municipio'                 => $municipio,
                    'local_verificacao'         => $local,
                    'tipo_medidor'              => $tipo ?: null,
                    'data_ultima_verificacao'   => $dataVerif,
                    'data_verificacao_efetiva'  => $dataVerif,
                    'data_validade'             => $dataVal,
                    'ultimo_resultado'          => $resultado ?: null,
                    'identity_hash'             => $chave,
                    'imported_at'               => $importedAt,
                    'updated_at'                => $importedAt,
                    '_faixas'                   => [],
                    '_series'                   => [],
                ];
            } else {
                // Mantém data mais recente
                if ($dataVerif && (!$grupos[$chave]['data_ultima_verificacao'] || $dataVerif > $grupos[$chave]['data_ultima_verificacao'])) {
                    $grupos[$chave]['data_ultima_verificacao']  = $dataVerif;
                    $grupos[$chave]['data_verificacao_efetiva'] = $dataVerif;
                    $grupos[$chave]['data_validade']            = $dataVal;
                    $grupos[$chave]['ultimo_resultado']         = $resultado ?: $grupos[$chave]['ultimo_resultado'];
                }
            }

            if ($faixa !== '' || $inmetro !== '' || $serie !== '') {
                $grupos[$chave]['_faixas'][] = [
                    'numero_faixa'    => $faixa  ?: null,
                    'numero_inmetro'  => $inmetro ?: null,
                    'numero_serie'    => $serie   ?: null,
                    'sentido'         => $sentido ?: null,
                    'velocidade_nominal' => $velocidade ?: null,
                ];
                if ($serie !== '') {
                    $grupos[$chave]['_series'][] = $serie;
                }
            }
        }

        $batches = array_chunk(array_values($grupos), self::BATCH_SIZE);
        foreach ($batches as $batch) {
            foreach ($batch as $g) {
                $faixas  = $g['_faixas'];
                $series  = array_unique(array_filter($g['_series']));
                unset($g['_faixas'], $g['_series']);

                $g['faixas_json'] = json_encode($faixas, JSON_UNESCAPED_UNICODE);
                $g['row_hash']    = hash('sha256', $g['faixas_json'] . $g['local_verificacao']);

                $result = $this->upsertRadar($g, $series, $dryRun);
                $stats[$result]++;

                if ($result !== 'sem_mudanca') {
                    $io->writeln(sprintf(
                        '  [%s] %s %s — %s (%d faixas)',
                        strtoupper($result[0]),
                        $g['sigla_uf'] ?? '??',
                        $g['municipio'],
                        $g['local_verificacao'],
                        count($faixas)
                    ));
                }
            }
        }

        return $stats;
    }

    // ════════════════════════════════════════════════════════════
    // Aba 2 — Verificações
    // Atualiza datas/resultado em registros já existentes.
    // Colunas: Município | Data Verificação | Data Validade | Resultado | Local
    // ════════════════════════════════════════════════════════════

    private function processVerificacoes(array $rows, string $uf, bool $dryRun, SymfonyStyle $io): array
    {
        $stats      = ['inseridos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0];
        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $municipio = $this->normalizeStr($row['municipio']  ?? $row['município'] ?? '');
            $local     = $this->normalizeStr($row['local']      ?? '');
            $tipo      = $this->normalizeStr($row['tipo']       ?? $row['tipo_medidor'] ?? '');
            $dataVerif = $this->parseDate($row['dataverificacao'] ?? $row['data verificação'] ?? '');
            $dataVal   = $this->parseDate($row['datavalidade']    ?? $row['data validade']    ?? '');
            $resultado = $this->normalizeStr($row['resultado']  ?? '');

            if ($local === '') {
                continue;
            }

            $hash = $this->identityHash($uf, $local, $tipo);

            $existing = $this->db->fetchAssociative(
                'SELECT id, data_ultima_verificacao, data_validade, ultimo_resultado FROM radar_medidor WHERE identity_hash = ? LIMIT 1',
                [$hash]
            );

            if (!$existing) {
                // Insere como registro novo mínimo
                $g = [
                    'sigla_uf'                 => $uf ?: null,
                    'municipio'                => $municipio,
                    'local_verificacao'        => $local,
                    'tipo_medidor'             => $tipo ?: null,
                    'data_ultima_verificacao'  => $dataVerif,
                    'data_verificacao_efetiva' => $dataVerif,
                    'data_validade'            => $dataVal,
                    'ultimo_resultado'         => $resultado ?: null,
                    'identity_hash'            => $hash,
                    'faixas_json'              => '[]',
                    'row_hash'                 => hash('sha256', $hash . $dataVal),
                    'imported_at'              => $importedAt,
                    'updated_at'               => $importedAt,
                ];
                if (!$dryRun) {
                    $this->db->insert('radar_medidor', $g);
                }
                $stats['inseridos']++;
                $io->writeln("  [I] {$uf} {$municipio} — {$local}");
                continue;
            }

            // Atualiza apenas se data mais recente
            $dataAtualIso = $this->toIso($existing['data_ultima_verificacao']);

            if ($dataVerif && $dataVerif > ($dataAtualIso ?? '')) {
                if (!$dryRun) {
                    $this->db->update('radar_medidor', [
                        'data_ultima_verificacao'  => $dataVerif,
                        'data_verificacao_efetiva' => $dataVerif,
                        'data_validade'            => $dataVal,
                        'ultimo_resultado'         => $resultado ?: $existing['ultimo_resultado'],
                        'updated_at'               => $importedAt,
                    ], ['id' => $existing['id']]);
                }
                $stats['atualizados']++;
                $io->writeln("  [U] {$uf} {$municipio} — {$local} (nova data: {$dataVerif})");
            } else {
                $stats['sem_mudanca']++;
            }
        }

        return $stats;
    }

    // ════════════════════════════════════════════════════════════
    // Aba 3 — Links Waze
    // Colunas esperadas: Local | Link  (ou Serie | Link, Inmetro | Link)
    // ════════════════════════════════════════════════════════════

    private function processLinks(array $rows, string $uf, bool $dryRun, SymfonyStyle $io): array
    {
        $stats = ['links' => 0, 'erros' => 0];
        $agora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $link    = trim($row['link'] ?? $row['linkwaze'] ?? $row['link_waze'] ?? $row['waze'] ?? '');
            $local   = $this->normalizeStr($row['local'] ?? $row['localverificacao'] ?? '');
            $serie   = trim($row['série']  ?? $row['serie']  ?? $row['numeroserie']  ?? '');
            $inmetro = trim($row['inmetro'] ?? $row['numeroinmetro'] ?? '');
            $tipo    = $this->normalizeStr($row['tipo'] ?? '');

            if ($link === '') {
                continue;
            }

            // Valida URL mínima
            if (!filter_var($link, FILTER_VALIDATE_URL)) {
                $io->writeln("  [!] URL inválida ignorada: {$link}");
                $stats['erros']++;
                continue;
            }

            // Extrai hazard ID se for link Waze
            preg_match('/permanentHazards=(\d+)/i', $link, $m);
            $hazardId = isset($m[1]) ? (int) $m[1] : null;

            // Lookup: 1º por série, 2º por inmetro, 3º por local+tipo
            $radarId = null;

            if ($serie !== '') {
                $radarId = $this->lookupBySerie($serie);
            }
            if ($radarId === null && $inmetro !== '') {
                $radarId = $this->lookupByInmetro($inmetro);
            }
            if ($radarId === null && $local !== '') {
                $hash    = $this->identityHash($uf, $local, $tipo);
                $radarId = $this->lookupByHash($hash);
            }

            if ($radarId === null) {
                $io->writeln("  [!] Radar não encontrado para link: {$link} (local={$local}, serie={$serie})");
                $stats['erros']++;
                continue;
            }

            if (!$dryRun) {
                $this->saveWazeLink($radarId, $link, $hazardId, $agora);
            }

            $stats['links']++;
            $io->writeln("  [L] radar_id={$radarId} → {$link}");
        }

        return $stats;
    }

    // ════════════════════════════════════════════════════════════
    // Upsert principal com merge tri-nível
    // ════════════════════════════════════════════════════════════

    private function upsertRadar(array $data, array $series, bool $dryRun): string
    {
        $agora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Nível 1: busca por qualquer número de série das faixas
        $existing = null;
        foreach ($series as $serie) {
            $existing = $this->db->fetchAssociative(
                "SELECT id, data_ultima_verificacao, faixas_json, identity_hash, row_hash
                 FROM radar_medidor
                 WHERE JSON_SEARCH(faixas_json, 'one', ?) IS NOT NULL LIMIT 1",
                [$serie]
            );
            if ($existing) {
                break;
            }
        }

        // Nível 2: busca por identity_hash (local+tipo)
        if (!$existing) {
            $existing = $this->db->fetchAssociative(
                'SELECT id, data_ultima_verificacao, faixas_json, identity_hash, row_hash
                 FROM radar_medidor WHERE identity_hash = ? LIMIT 1',
                [$data['identity_hash']]
            );
        }

        // Nível 3: INSERT
        if (!$existing) {
            if (!$dryRun) {
                $this->db->insert('radar_medidor', array_diff_key($data, ['_faixas' => 0, '_series' => 0]));
            }
            return 'inseridos';
        }

        // Compara row_hash — se igual, nada mudou
        if ($existing['row_hash'] === $data['row_hash']) {
            return 'sem_mudanca';
        }

        // UPDATE — mescla faixas existentes com novas
        $faixasExistentes = json_decode((string) $existing['faixas_json'], true) ?? [];
        $faixasNovas      = json_decode((string) $data['faixas_json'],     true) ?? [];
        $faixasMerged     = $this->mergeFaixas($faixasExistentes, $faixasNovas);

        $dataExistIso = $this->toIso($existing['data_ultima_verificacao']);
        $dataNova     = $data['data_ultima_verificacao'];

        $update = [
            'faixas_json'  => json_encode($faixasMerged, JSON_UNESCAPED_UNICODE),
            'row_hash'     => $data['row_hash'],
            'updated_at'   => $agora,
        ];

        // Atualiza datas apenas se mais recentes
        if ($dataNova && $dataNova > ($dataExistIso ?? '')) {
            $update['data_ultima_verificacao']  = $dataNova;
            $update['data_verificacao_efetiva'] = $dataNova;
            $update['data_validade']            = $data['data_validade'];
            $update['ultimo_resultado']         = $data['ultimo_resultado'];
        }

        // Preenche campos vazios no banco com dados da planilha
        $row = $this->db->fetchAssociative('SELECT * FROM radar_medidor WHERE id = ?', [$existing['id']]);
        foreach (['sigla_uf', 'municipio', 'tipo_medidor', 'local_verificacao'] as $col) {
            if (empty($row[$col]) && !empty($data[$col])) {
                $update[$col] = $data[$col];
            }
        }

        if (!$dryRun) {
            $this->db->update('radar_medidor', $update, ['id' => $existing['id']]);
        }

        return 'atualizados';
    }

    // ════════════════════════════════════════════════════════════
    // Merge de faixas: une por numero_serie; se não tiver, por numero_faixa
    // ════════════════════════════════════════════════════════════

    private function mergeFaixas(array $existentes, array $novas): array
    {
        $indexados = [];
        foreach ($existentes as $f) {
            $key = $f['numero_serie'] ?? ('faixa_' . ($f['numero_faixa'] ?? uniqid()));
            $indexados[$key] = $f;
        }
        foreach ($novas as $f) {
            $key = $f['numero_serie'] ?? ('faixa_' . ($f['numero_faixa'] ?? uniqid()));
            if (isset($indexados[$key])) {
                // Preserva dado existente, complementa vazio
                foreach ($f as $k => $v) {
                    if (($v !== null && $v !== '') && (empty($indexados[$key][$k]))) {
                        $indexados[$key][$k] = $v;
                    }
                }
            } else {
                $indexados[$key] = $f;
            }
        }
        return array_values($indexados);
    }

    // ════════════════════════════════════════════════════════════
    // Salvar link Waze (insert ou update)
    // ════════════════════════════════════════════════════════════

    private function saveWazeLink(int $radarId, string $link, ?int $hazardId, string $agora): void
    {
        $existing = $this->db->fetchAssociative(
            'SELECT id, waze_link FROM radar_waze_link WHERE radar_medidor_id = ? LIMIT 1',
            [$radarId]
        );

        if ($existing) {
            if ($existing['waze_link'] === $link) {
                return; // já está atualizado
            }
            $this->db->insert('radar_waze_link_log', [
                'radar_waze_link_id' => $existing['id'],
                'campo_alterado'     => 'waze_link',
                'valor_anterior'     => $existing['waze_link'],
                'valor_novo'         => $link,
                'changed_by'         => null,
                'changed_at'         => $agora,
            ]);
            $this->db->update('radar_waze_link', [
                'waze_link'           => $link,
                'permanent_hazard_id' => $hazardId,
                'updated_at'          => $agora,
            ], ['radar_medidor_id' => $radarId]);
        } else {
            $this->db->insert('radar_waze_link', [
                'radar_medidor_id'    => $radarId,
                'waze_link'           => $link,
                'permanent_hazard_id' => $hazardId,
                'inserted_at'         => $agora,
            ]);
        }

        $this->db->update('radar_medidor', ['link_waze' => $link, 'updated_at' => $agora], ['id' => $radarId]);
    }

    // ════════════════════════════════════════════════════════════
    // Lookups
    // ════════════════════════════════════════════════════════════

    private function lookupBySerie(string $serie): ?int
    {
        $row = $this->db->fetchAssociative(
            "SELECT id FROM radar_medidor WHERE JSON_SEARCH(faixas_json, 'one', ?) IS NOT NULL LIMIT 1",
            [$serie]
        );
        return $row ? (int) $row['id'] : null;
    }

    private function lookupByInmetro(string $inmetro): ?int
    {
        $row = $this->db->fetchAssociative(
            "SELECT id FROM radar_medidor WHERE JSON_SEARCH(faixas_json, 'one', ?) IS NOT NULL LIMIT 1",
            [$inmetro]
        );
        return $row ? (int) $row['id'] : null;
    }

    private function lookupByHash(string $hash): ?int
    {
        $row = $this->db->fetchAssociative(
            'SELECT id FROM radar_medidor WHERE identity_hash = ? LIMIT 1',
            [$hash]
        );
        return $row ? (int) $row['id'] : null;
    }

    // ════════════════════════════════════════════════════════════
    // Download CSV do Google Sheets
    // ════════════════════════════════════════════════════════════

    private function downloadCsv(string $url, SymfonyStyle $io): ?array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'radar_multi_');
        if ($tmpPath === false) {
            $io->error('Não foi possível criar arquivo temporário.');
            return null;
        }

        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            $io->error("Não foi possível abrir para escrita: {$tmpPath}");
            return null;
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
        fclose($fp);

        if (!$ok || $errCode !== 0) {
            @unlink($tmpPath);
            $io->error("cURL erro {$errCode}: {$errMsg} — {$url}");
            return null;
        }

        $rows = $this->parseCsv($tmpPath);
        @unlink($tmpPath);

        $io->writeln(sprintf('  Baixadas %d linhas de %s', count($rows), $url));
        return $rows;
    }

    // ════════════════════════════════════════════════════════════
    // Parse CSV com detecção automática de separador
    // Retorna array de arrays associativos com chaves normalizadas
    // ════════════════════════════════════════════════════════════

    private function parseCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }

        // Detecta separador
        $firstLine = fgets($fh);
        rewind($fh);
        $sep = (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) ? "\t" : ',';

        $headers = null;
        $rows    = [];

        while (($cols = fgetcsv($fh, 0, $sep)) !== false) {
            if ($cols === [null]) {
                continue;
            }
            if ($headers === null) {
                $headers = array_map(fn($h) => $this->normalizeKey($h), $cols);
                continue;
            }
            // Alinha colunas
            $cols = array_pad($cols, count($headers), '');
            $row  = array_combine($headers, array_slice($cols, 0, count($headers)));
            // Pula linhas completamente vazias
            if (implode('', $row) === '') {
                continue;
            }
            $rows[] = $row;
        }

        fclose($fh);
        return $rows;
    }

    // ════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════

    /** Normaliza chave de cabeçalho: lowercase, sem acentos, sem espaços */
    private function normalizeKey(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ü'=>'u','ç'=>'c',
            'Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a','É'=>'e','Ê'=>'e','Í'=>'i','Ó'=>'o','Õ'=>'o','Ô'=>'o','Ú'=>'u','Ü'=>'u','Ç'=>'c',
        ]);
        return preg_replace('/[^a-z0-9]/', '', $s) ?? $s;
    }

    /** Normaliza valor de string: trim + uppercase */
    private function normalizeStr(string $s): string
    {
        return mb_strtoupper(trim($s));
    }

    /**
     * Converte data BR (dd/mm/yyyy) ou ISO (yyyy-mm-dd) para ISO.
     * Retorna null se inválida.
     */
    private function parseDate(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        // Formato BR dd/mm/yyyy
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        // Já em ISO
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return substr($s, 0, 10);
        }
        return null;
    }

    /** Converte data do banco (qualquer formato) para ISO yyyy-mm-dd */
    private function toIso(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        return $this->parseDate($s) ?? substr($s, 0, 10);
    }

    /** SHA-256 do triplete UF|LOCAL_NORMALIZADO|TIPO — idêntico ao ImportRadarGoogleSheetsHandler */
    private function identityHash(string $uf, string $local, string $tipo): string
    {
        $local = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $local) ?? $local));
        $tipo  = mb_strtoupper(trim($tipo));
        $uf    = mb_strtoupper(trim($uf));
        return hash('sha256', "{$uf}|{$local}|{$tipo}");
    }
}
