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
 * Importa radares de planilha Google Sheets publicada (pub?output=csv).
 *
 * MODOS DE USO:
 *
 * A) URL completa (planilha publicada via "Publicar na web"):
 *   --url-medidores=https://docs.google.com/.../pub?gid=0&single=true&output=csv
 *   --url-expandida=https://docs.google.com/.../pub?gid=1556313574&...
 *   --url-links=https://docs.google.com/.../pub?gid=322830462&...
 *
 * B) ID + GID (planilha pública para qualquer pessoa com o link):
 *   --spreadsheet-id=ID_DA_PLANILHA --gid-medidores=0 --gid-expandida=111 --gid-links=222
 *
 * As opções --url-* têm prioridade sobre --spreadsheet-id + --gid-*.
 *
 * --dry-run  Simula sem gravar no banco.
 */
#[AsCommand(
    name: 'app:import-radar-multi-aba',
    description: 'Importa radares de planilha Google Sheets com até 3 abas (simples, expandida, links Waze)',
)]
final class ImportRadarMultiAbaCommand extends Command
{
    private const CURL_TIMEOUT = 120;
    private const BATCH_SIZE   = 200;
    private const EXPORT_URL   = 'https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%s';

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            // Modo A: URLs completas
            ->addOption('url-medidores', null, InputOption::VALUE_OPTIONAL, 'URL pub CSV da aba simples (Município|Local|Tipo|Faixa|Inmetro|Série...)')
            ->addOption('url-expandida', null, InputOption::VALUE_OPTIONAL, 'URL pub CSV da aba expandida (SiglaUf|Faixas.N.*|Historico.N.*|Proprietario.*)')
            ->addOption('url-links',     null, InputOption::VALUE_OPTIONAL, 'URL pub CSV da aba de links Waze (LINK|Nº DE SÉRIE|CIDADE...)')
            // Modo B: ID + GID
            ->addOption('spreadsheet-id', 's', InputOption::VALUE_OPTIONAL, 'ID da planilha Google Sheets (requer planilha pública)')
            ->addOption('gid-medidores',  null, InputOption::VALUE_OPTIONAL, 'GID da aba simples')
            ->addOption('gid-expandida',  null, InputOption::VALUE_OPTIONAL, 'GID da aba expandida')
            ->addOption('gid-links',      null, InputOption::VALUE_OPTIONAL, 'GID da aba de links')
            // Comum
            ->addOption('uf', 'u', InputOption::VALUE_OPTIONAL, 'Sigla UF (ex: AC). Obrigatório para abas sem coluna SiglaUf.', '')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem gravar no banco')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $uf     = strtoupper(trim((string) $input->getOption('uf')));
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->warning('MODO DRY-RUN — nenhuma alteração será gravada.');
        }

        // Resolve URLs (Modo A tem prioridade)
        $urlMedidores = $this->resolveUrl($input, 'medidores');
        $urlExpandida = $this->resolveUrl($input, 'expandida');
        $urlLinks     = $this->resolveUrl($input, 'links');

        if ($urlMedidores === null && $urlExpandida === null && $urlLinks === null) {
            $io->error('Informe pelo menos uma URL (--url-medidores, --url-expandida, --url-links) ou --spreadsheet-id com --gid-*.');
            return Command::FAILURE;
        }

        $totais = ['inseridos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'links' => 0, 'erros' => 0];

        if ($urlMedidores !== null) {
            $io->section("Aba simples");
            $io->writeln("  URL: {$urlMedidores}");
            $rows = $this->downloadCsv($urlMedidores, $io);
            if ($rows !== null) {
                $this->somaStats($totais, $this->processAbaSimples($rows, $uf, $dryRun, $io));
            }
        }

        if ($urlExpandida !== null) {
            $io->section("Aba expandida");
            $io->writeln("  URL: {$urlExpandida}");
            $rows = $this->downloadCsv($urlExpandida, $io);
            if ($rows !== null) {
                $this->somaStats($totais, $this->processAbaExpandida($rows, $uf, $dryRun, $io));
            }
        }

        if ($urlLinks !== null) {
            $io->section("Aba links");
            $io->writeln("  URL: {$urlLinks}");
            $rows = $this->downloadCsv($urlLinks, $io);
            if ($rows !== null) {
                $r = $this->processAbaLinks($rows, $dryRun, $io);
                $totais['links'] += $r['links'];
                $totais['erros'] += $r['erros'];
            }
        }

        $io->success(sprintf(
            'Concluído — inseridos: %d | atualizados: %d | sem mudança: %d | links: %d | erros: %d',
            $totais['inseridos'], $totais['atualizados'], $totais['sem_mudanca'], $totais['links'], $totais['erros']
        ));

        return Command::SUCCESS;
    }

    // ============================================================
    // Resolve URL: Modo A (--url-*) ou Modo B (--spreadsheet-id + --gid-*)
    // ============================================================

    private function resolveUrl(InputInterface $input, string $aba): ?string
    {
        $urlOpt = $input->getOption('url-' . $aba);
        if ($urlOpt !== null && $urlOpt !== '') {
            return trim($urlOpt);
        }

        $spreadsheetId = trim((string) $input->getOption('spreadsheet-id'));
        $gid = $input->getOption('gid-' . $aba);

        if ($spreadsheetId !== '' && $gid !== null && $gid !== '') {
            return sprintf(self::EXPORT_URL, $spreadsheetId, $gid);
        }

        return null;
    }

    // ============================================================
    // ABA 1 — Simples
    // Município | Data Verificação | Data Validade | Resultado |
    // Local | Tipo | Faixa | Inmetro | Série | Sentido | Velocidade
    // ============================================================

    private function processAbaSimples(array $rows, string $uf, bool $dryRun, SymfonyStyle $io): array
    {
        $stats      = ['inseridos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0];
        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $grupos     = [];

        foreach ($rows as $row) {
            $municipio  = $this->str($row['municipio'] ?? '');
            $local      = $this->str($row['local']     ?? '');
            $tipo       = $this->str($row['tipo']      ?? '');
            $dataVerif  = $this->parseDate($row['dataverificacao'] ?? $row['data verificacao'] ?? '');
            $dataVal    = $this->parseDate($row['datavalidade']    ?? $row['data validade']    ?? '');
            $resultado  = $this->str($row['resultado'] ?? '');
            $faixa      = trim($row['faixa']     ?? '');
            $inmetro    = trim($row['inmetro']   ?? '');
            $serie      = trim($row['serie']     ?? '');
            $sentido    = $this->str($row['sentido']   ?? '');
            $velocidade = trim($row['velocidade'] ?? '');

            if ($local === '' || $municipio === '') {
                continue;
            }

            $siglaUf = $uf !== '' ? $uf : null;
            $chave   = $this->identityHash($siglaUf ?? '', $local, $tipo);

            if (!isset($grupos[$chave])) {
                $grupos[$chave] = [
                    'sigla_uf'                 => $siglaUf,
                    'municipio'                => $municipio,
                    'local_verificacao'        => $local,
                    'tipo_medidor'             => $tipo   ?: null,
                    'data_ultima_verificacao'  => $dataVerif,
                    'data_verificacao_efetiva' => $dataVerif,
                    'data_validade'            => $dataVal,
                    'ultimo_resultado'         => $resultado ?: null,
                    'identity_hash'            => $chave,
                    'imported_at'              => $importedAt,
                    'updated_at'               => $importedAt,
                    '_faixas'                  => [],
                ];
            } else {
                if ($dataVerif && $dataVerif > ($grupos[$chave]['data_ultima_verificacao'] ?? '')) {
                    $grupos[$chave]['data_ultima_verificacao']  = $dataVerif;
                    $grupos[$chave]['data_verificacao_efetiva'] = $dataVerif;
                    $grupos[$chave]['data_validade']            = $dataVal;
                    $grupos[$chave]['ultimo_resultado']         = $resultado ?: $grupos[$chave]['ultimo_resultado'];
                }
            }

            if ($faixa !== '' || $inmetro !== '' || $serie !== '') {
                $grupos[$chave]['_faixas'][] = [
                    'numero_faixa'       => $faixa      ?: null,
                    'numero_inmetro'     => $inmetro    ?: null,
                    'numero_serie'       => $serie      ?: null,
                    'sentido'            => $sentido    ?: null,
                    'velocidade_nominal' => $velocidade ?: null,
                ];
            }
        }

        foreach (array_chunk(array_values($grupos), self::BATCH_SIZE) as $batch) {
            foreach ($batch as $g) {
                $faixas = $g['_faixas'];
                $series = array_unique(array_filter(array_column($faixas, 'numero_serie')));
                unset($g['_faixas']);
                $g['faixas_json'] = json_encode($faixas, JSON_UNESCAPED_UNICODE);
                $g['row_hash']    = hash('sha256', $g['faixas_json'] . $g['local_verificacao']);

                $result = $this->upsertRadar($g, $series, $dryRun);
                $stats[$result]++;

                if ($result !== 'sem_mudanca') {
                    $io->writeln(sprintf('  [%s] %s %s — %s (%d faixas)',
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

    // ============================================================
    // ABA 2 — Expandida (TSV RBMLQ completo)
    // SiglaUf | Estado | Municipio | LocalVerificacao |
    // DataUltimaVerificacao | DataValidade | UltimoResultado | TipoMedidor |
    // Faixas.0.* ... | Historico.0.* ... | Proprietario.*
    // ============================================================

    private function processAbaExpandida(array $rows, string $ufParam, bool $dryRun, SymfonyStyle $io): array
    {
        $stats      = ['inseridos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0];
        $importedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $siglaUf   = $this->str($row['siglauf']  ?? $row['uf'] ?? $ufParam);
            $estado    = $this->str($row['estado']   ?? '');
            $municipio = $this->str($row['municipio'] ?? '');
            $local     = $this->str($row['localverificacao'] ?? $row['local'] ?? '');
            $dataVerif = $this->parseDate($row['dataultimaverificacao'] ?? $row['dataverificacao'] ?? '');
            $dataVal   = $this->parseDate($row['datavalidade'] ?? '');
            $resultado = $this->str($row['ultimoresultado'] ?? $row['resultado'] ?? '');
            $tipo      = $this->str($row['tipomedidor'] ?? $row['tipo'] ?? '');
            $propNome  = trim($row['proprietarionome'] ?? '');

            if ($local === '' || $municipio === '') {
                continue;
            }

            $faixas    = $this->extrairFaixasExpandidas($row);
            $historico = $this->extrairHistoricoExpandido($row);
            $chave     = $this->identityHash($siglaUf, $local, $tipo);
            $series    = array_unique(array_filter(array_column($faixas, 'numero_serie')));

            $data = [
                'sigla_uf'                 => $siglaUf  ?: null,
                'uf'                       => $estado    ?: null,
                'municipio'                => $municipio,
                'local_verificacao'        => $local,
                'tipo_medidor'             => $tipo      ?: null,
                'data_ultima_verificacao'  => $dataVerif,
                'data_verificacao_efetiva' => $dataVerif,
                'data_validade'            => $dataVal,
                'ultimo_resultado'         => $resultado ?: null,
                'nome_empresa'             => $propNome  ?: null,
                'identity_hash'            => $chave,
                'faixas_json'              => json_encode($faixas, JSON_UNESCAPED_UNICODE),
                'raw_data'                 => json_encode($row,    JSON_UNESCAPED_UNICODE),
                'imported_at'              => $importedAt,
                'updated_at'               => $importedAt,
            ];
            $data['row_hash'] = hash('sha256', $data['faixas_json'] . $local);

            $result = $this->upsertRadar($data, $series, $dryRun);
            $stats[$result]++;

            if ($result !== 'sem_mudanca' && !$dryRun && count($historico) > 0) {
                $radarId = (int) $this->db->fetchOne(
                    'SELECT id FROM radar_medidor WHERE identity_hash = ? LIMIT 1', [$chave]
                );
                if ($radarId > 0) {
                    $this->salvarHistorico($radarId, $historico, $importedAt);
                }
            }

            if ($result !== 'sem_mudanca') {
                $io->writeln(sprintf('  [%s] %s %s — %s (%d faixas, %d hist.)',
                    strtoupper($result[0]), $siglaUf, $municipio, $local,
                    count($faixas), count($historico)
                ));
            }
        }

        return $stats;
    }

    // ============================================================
    // ABA 3 — Links Waze
    // LINK | Nº DE SÉRIE | NOVO | EXPIRADO | CIDADE | USUÁRIO | VERIFICADO | ALTERADO | AÇÃO
    // ============================================================

    private function processAbaLinks(array $rows, bool $dryRun, SymfonyStyle $io): array
    {
        $stats = ['links' => 0, 'erros' => 0];
        $agora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $link   = trim($row['link']    ?? $row['linkwaze'] ?? '');
            $serie  = trim($row['ndeserie'] ?? $row['serie']  ?? $row['numeroserie'] ?? '');
            $cidade = $this->str($row['cidade'] ?? '');
            $novo   = trim($row['novo']    ?? '');
            $acao   = $this->str($row['acao']   ?? '');

            if ($novo !== '' && filter_var($novo, FILTER_VALIDATE_URL)) {
                $link = $novo;
            }

            if ($link === '') {
                continue;
            }
            if (!filter_var($link, FILTER_VALIDATE_URL)) {
                $io->writeln("  [!] URL inválida: {$link}");
                $stats['erros']++;
                continue;
            }

            preg_match('/permanentHazards=(\d+)/i', $link, $m);
            $hazardId = isset($m[1]) ? (int) $m[1] : null;

            $radarId = null;
            if ($serie !== '') {
                $radarId = $this->lookupBySerie($serie);
            }

            if ($radarId === null) {
                $io->writeln("  [!] Radar não encontrado — serie={$serie} cidade={$cidade}");
                $stats['erros']++;
                continue;
            }

            if (!$dryRun) {
                $this->saveWazeLink($radarId, $link, $hazardId, $agora);
            }

            $io->writeln("  [L] radar_id={$radarId} serie={$serie} hazard={$hazardId} acao={$acao}");
            $stats['links']++;
        }

        return $stats;
    }

    // ============================================================
    // Extrai faixas dinâmicas: Faixas.0.*, Faixas.1.*, ...
    // ============================================================

    private function extrairFaixasExpandidas(array $row): array
    {
        $faixas = [];
        for ($i = 0; $i <= 10; $i++) {
            $pfx        = 'faixas' . $i;
            $numFaixa   = trim($row[$pfx . 'numerofaixa']       ?? '');
            $inmetro    = trim($row[$pfx . 'numeroinmetro']     ?? '');
            $serie      = trim($row[$pfx . 'numeroserie']       ?? '');
            $sentido    = trim($row[$pfx . 'sentido']           ?? '');
            $velocidade = trim($row[$pfx . 'velocidadenominal'] ?? '');

            if ($numFaixa === '' && $inmetro === '' && $serie === '') {
                break;
            }

            $faixas[] = [
                'numero_faixa'       => $numFaixa   ?: null,
                'numero_inmetro'     => $inmetro    ?: null,
                'numero_serie'       => $serie      ?: null,
                'sentido'            => $sentido    ? mb_strtoupper($sentido) : null,
                'velocidade_nominal' => $velocidade ?: null,
            ];
        }
        return $faixas;
    }

    // ============================================================
    // Extrai histórico dinâmico: Historico.0.*, Historico.1.*, ...
    // ============================================================

    private function extrairHistoricoExpandido(array $row): array
    {
        $historico = [];
        for ($i = 0; $i <= 10; $i++) {
            $pfx    = 'historico' . $i;
            $cert   = trim($row[$pfx . 'numerocertificado'] ?? '');
            $ensaio = trim($row[$pfx . 'numeroensaio']      ?? '');
            $ano    = trim($row[$pfx . 'ano']               ?? '');
            $laudo  = $this->parseDate($row[$pfx . 'datalaudo']   ?? '');
            $valH   = $this->parseDate($row[$pfx . 'datavalidade'] ?? '');
            $tipo   = trim($row[$pfx . 'tiposervico']  ?? '');
            $res    = trim($row[$pfx . 'resultado']    ?? '');

            if ($cert === '' && $laudo === null) {
                break;
            }

            $historico[] = [
                'numero_certificado' => $cert    ?: null,
                'numero_ensaio'      => $ensaio  ?: null,
                'ano'                => $ano     ?: null,
                'data_laudo'         => $laudo,
                'data_validade'      => $valH,
                'tipo_servico'       => $tipo    ?: null,
                'resultado'          => $res     ?: null,
            ];
        }
        return $historico;
    }

    // ============================================================
    // Salva histórico em radar_historico (ignora duplicatas)
    // ============================================================

    private function salvarHistorico(int $radarId, array $historico, string $agora): void
    {
        foreach ($historico as $h) {
            $exists = $this->db->fetchOne(
                'SELECT id FROM radar_historico
                  WHERE radar_medidor_id = ?
                    AND (numero_certificado = ? OR data_laudo = ?)
                  LIMIT 1',
                [$radarId, $h['numero_certificado'], $h['data_laudo']]
            );
            if ($exists) {
                continue;
            }
            $this->db->insert('radar_historico', array_merge($h, [
                'radar_medidor_id' => $radarId,
                'imported_at'      => $agora,
            ]));
        }
    }

    // ============================================================
    // Upsert tri-nível: serie → identity_hash → INSERT
    // ============================================================

    private function upsertRadar(array $data, array $series, bool $dryRun): string
    {
        $agora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = null;
        foreach ($series as $serie) {
            $existing = $this->db->fetchAssociative(
                "SELECT id, data_ultima_verificacao, faixas_json, row_hash
                 FROM radar_medidor
                 WHERE JSON_SEARCH(faixas_json, 'one', ?) IS NOT NULL LIMIT 1",
                [$serie]
            );
            if ($existing) break;
        }

        if (!$existing) {
            $existing = $this->db->fetchAssociative(
                'SELECT id, data_ultima_verificacao, faixas_json, row_hash
                 FROM radar_medidor WHERE identity_hash = ? LIMIT 1',
                [$data['identity_hash']]
            );
        }

        if (!$existing) {
            if (!$dryRun) {
                $insert = $data;
                unset($insert['_faixas']);
                $this->db->insert('radar_medidor', $insert);
            }
            return 'inseridos';
        }

        if ($existing['row_hash'] === $data['row_hash']) {
            return 'sem_mudanca';
        }

        $faixasExist  = json_decode((string) $existing['faixas_json'], true) ?? [];
        $faixasNovas  = json_decode((string) $data['faixas_json'],     true) ?? [];
        $faixasMerged = $this->mergeFaixas($faixasExist, $faixasNovas);

        $dataExistIso = $this->toIso($existing['data_ultima_verificacao']);

        $update = [
            'faixas_json' => json_encode($faixasMerged, JSON_UNESCAPED_UNICODE),
            'row_hash'    => $data['row_hash'],
            'updated_at'  => $agora,
        ];

        if ($data['data_ultima_verificacao'] && $data['data_ultima_verificacao'] > ($dataExistIso ?? '')) {
            $update['data_ultima_verificacao']  = $data['data_ultima_verificacao'];
            $update['data_verificacao_efetiva'] = $data['data_ultima_verificacao'];
            $update['data_validade']            = $data['data_validade'];
            $update['ultimo_resultado']         = $data['ultimo_resultado'];
        }

        $rowAtual = $this->db->fetchAssociative('SELECT * FROM radar_medidor WHERE id = ?', [$existing['id']]);
        foreach (['sigla_uf','uf','municipio','tipo_medidor','local_verificacao','nome_empresa'] as $col) {
            if (!empty($data[$col]) && empty($rowAtual[$col])) {
                $update[$col] = $data[$col];
            }
        }

        if (!$dryRun) {
            $this->db->update('radar_medidor', $update, ['id' => $existing['id']]);
        }

        return 'atualizados';
    }

    // ============================================================
    // Merge de faixas por numero_serie
    // ============================================================

    private function mergeFaixas(array $existentes, array $novas): array
    {
        $idx = [];
        foreach ($existentes as $f) {
            $key = $f['numero_serie'] ?? ('faixa_' . ($f['numero_faixa'] ?? uniqid()));
            $idx[$key] = $f;
        }
        foreach ($novas as $f) {
            $key = $f['numero_serie'] ?? ('faixa_' . ($f['numero_faixa'] ?? uniqid()));
            if (isset($idx[$key])) {
                foreach ($f as $k => $v) {
                    if (($v !== null && $v !== '') && empty($idx[$key][$k])) {
                        $idx[$key][$k] = $v;
                    }
                }
            } else {
                $idx[$key] = $f;
            }
        }
        return array_values($idx);
    }

    // ============================================================
    // Salvar link Waze
    // ============================================================

    private function saveWazeLink(int $radarId, string $link, ?int $hazardId, string $agora): void
    {
        $existing = $this->db->fetchAssociative(
            'SELECT id, waze_link FROM radar_waze_link WHERE radar_medidor_id = ? LIMIT 1',
            [$radarId]
        );

        if ($existing) {
            if ($existing['waze_link'] === $link) return;
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

        $this->db->update('radar_medidor', [
            'link_waze'  => $link,
            'updated_at' => $agora,
        ], ['id' => $radarId]);
    }

    // ============================================================
    // Lookups
    // ============================================================

    private function lookupBySerie(string $serie): ?int
    {
        $row = $this->db->fetchAssociative(
            "SELECT id FROM radar_medidor
             WHERE JSON_SEARCH(faixas_json, 'one', ?) IS NOT NULL LIMIT 1",
            [$serie]
        );
        return $row ? (int) $row['id'] : null;
    }

    // ============================================================
    // Download CSV via cURL
    // ============================================================

    private function downloadCsv(string $url, SymfonyStyle $io): ?array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'radar_multi_');
        if ($tmpPath === false) {
            $io->error('Não foi possível criar arquivo temporário.');
            return null;
        }

        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            $io->error("Falha ao abrir para escrita: {$tmpPath}");
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT      => 'ToolboxWaze/1.0',
            CURLOPT_FAILONERROR    => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept: text/csv,text/plain,*/*'],
        ]);

        $ok      = curl_exec($ch);
        $errCode = curl_errno($ch);
        $errMsg  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $errCode !== 0) {
            @unlink($tmpPath);
            $io->error("cURL erro {$errCode} (HTTP {$httpCode}): {$errMsg} — {$url}");
            return null;
        }

        $rows = $this->parseCsv($tmpPath);
        @unlink($tmpPath);

        $io->writeln(sprintf('  %d linhas baixadas', count($rows)));
        return $rows;
    }

    // ============================================================
    // Parse CSV/TSV com detecção automática de separador
    // Chaves normalizadas: sem acentos, sem espaços/pontos, lowercase
    // "Faixas.0.NumeroFaixa" → "faixas0numerofaixa"
    // "Nº DE SÉRIE"          → "ndeserie"
    // ============================================================

    private function parseCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) return [];

        $firstLine = fgets($fh);
        rewind($fh);
        $sep = (substr_count((string) $firstLine, "\t") > substr_count((string) $firstLine, ','))
            ? "\t" : ',';

        $headers = null;
        $rows    = [];

        while (($cols = fgetcsv($fh, 0, $sep)) !== false) {
            if ($cols === [null]) continue;
            if ($headers === null) {
                $headers = array_map(fn($h) => $this->normalizeKey($h), $cols);
                continue;
            }
            $cols = array_pad($cols, count($headers), '');
            $row  = array_combine($headers, array_slice($cols, 0, count($headers)));
            if (implode('', $row) === '') continue;
            $rows[] = $row;
        }

        fclose($fh);
        return $rows;
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function normalizeKey(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a',
            'é'=>'e','ê'=>'e',
            'í'=>'i','ï'=>'i',
            'ó'=>'o','õ'=>'o','ô'=>'o',
            'ú'=>'u','ü'=>'u','ù'=>'u',
            'ç'=>'c','ñ'=>'n',
            'Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a',
            'É'=>'e','Ê'=>'e',
            'Í'=>'i',
            'Ó'=>'o','Õ'=>'o','Ô'=>'o',
            'Ú'=>'u','Ü'=>'u',
            'Ç'=>'c','Ñ'=>'n',
            'º'=>'', 'ª'=>'', '°'=>'',
        ]);
        return preg_replace('/[^a-z0-9]/', '', $s) ?? $s;
    }

    private function str(string $s): string
    {
        return mb_strtoupper(trim($s));
    }

    private function parseDate(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return null;
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return substr($s, 0, 10);
        }
        return null;
    }

    private function toIso(?string $s): ?string
    {
        if ($s === null || $s === '') return null;
        return $this->parseDate($s) ?? substr($s, 0, 10);
    }

    private function identityHash(string $uf, string $local, string $tipo): string
    {
        $local = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $local) ?? $local));
        $tipo  = mb_strtoupper(trim($tipo));
        $uf    = mb_strtoupper(trim($uf));
        return hash('sha256', "{$uf}|{$local}|{$tipo}");
    }

    private function somaStats(array &$totais, array $r): void
    {
        foreach (['inseridos','atualizados','sem_mudanca','erros'] as $k) {
            $totais[$k] += $r[$k] ?? 0;
        }
    }
}
