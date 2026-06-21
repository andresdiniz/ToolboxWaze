<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ImportRadarMedidoresMessage;
use App\MessageHandler\ImportRadarMedidoresHandler;
use App\Repository\BrazilianStateRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa radares em três etapas:
 *
 * ETAPA 1 — Medidores RBMLQ/INMETRO via JSON (padrão)
 *   URL: https://servicos.rbmlq.gov.br/dados-abertos/{UF}/medidores.json
 *   Pode ser pulada com --skip-rbmlq
 *
 * ETAPA 1B — JSON local/externo com dados de radares (opcional)
 *   --file=/path/to/radares.json  ou  --url=https://exemplo.com/radares.json
 *   Campos aceitos: link_waze, municipio, logradouro, tipo_medidor, numero_serie,
 *                   faixas[], data_validade, data_ultima_verificacao,
 *                   cnpj_responsavel, sigla_uf
 *
 * ETAPA 2 — Links Waze via planilha Referencia.UF (CSV) — ÚNICA planilha mantida
 *   Cruza: REFERENCIA."Nº DE SÉRIE" = radar_faixa.numero_serie
 *   Grava: radar_medidor.link_waze
 *   Colunas: LINK: | Nº DE SÉRIE: | NOVO: | EXPIRADO: | CIDADE: | USUÁRIO: | VERIFICADO: | ALTERADO: | AÇÃO:
 *
 * DEDUPLICAÇÃO (3 camadas):
 *   1. numero_serie em radar_faixa (mais precisa)
 *   2. identity_hash SHA-256 de UF|local_normalizado|tipo_medidor
 *   3. UF + UPPER(municipio) + UPPER(logradouro)
 *
 * USO:
 *   php bin/console app:import-radares                        # todos os estados (RBMLQ)
 *   php bin/console app:import-radares --uf=MG                # só MG
 *   php bin/console app:import-radares --file=radares.json    # JSON local
 *   php bin/console app:import-radares --url=https://...      # JSON remoto
 *   php bin/console app:import-radares --skip-rbmlq           # pula RBMLQ, só links Waze
 *   php bin/console app:import-radares --skip-waze            # pula links Waze
 *   php bin/console app:import-radares --dry-run              # simula sem gravar
 */
#[AsCommand(
    name: 'app:import-radares',
    description: 'Importa radares: JSON RBMLQ/local (etapa 1) + links Waze Referencia.UF (etapa 2)',
)]
final class ImportRadarCommand extends Command
{
    private const CURL_TIMEOUT = 120;

    public function __construct(
        private readonly ImportRadarMedidoresHandler $rbmlqHandler,
        private readonly BrazilianStateRepository    $stateRepository,
        private readonly Connection                  $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'uf', null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filtra por UF(s). Ex: --uf=SP --uf=RJ', []
            )
            ->addOption(
                'file', null,
                InputOption::VALUE_REQUIRED,
                'Caminho para arquivo JSON local de radares'
            )
            ->addOption(
                'url', null,
                InputOption::VALUE_REQUIRED,
                'URL de JSON remoto de radares (baixa e importa)'
            )
            ->addOption(
                'skip-rbmlq', null,
                InputOption::VALUE_NONE,
                'Pula a importação RBMLQ (etapa 1 padrão)'
            )
            ->addOption(
                'skip-waze', null,
                InputOption::VALUE_NONE,
                'Pula a importação de links Waze via Referencia.UF (etapa 2)'
            )
            ->addOption(
                'dry-run', null,
                InputOption::VALUE_NONE,
                'Simula sem gravar nada no banco'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $skipRbmlq = (bool) $input->getOption('skip-rbmlq');
        $skipWaze  = (bool) $input->getOption('skip-waze');
        $dryRun    = (bool) $input->getOption('dry-run');
        $jsonFile  = $input->getOption('file');
        $jsonUrl   = $input->getOption('url');

        $io->title('Importação Radares — JSON' . ($dryRun ? ' [DRY-RUN]' : ''));

        if ($dryRun) {
            $io->warning('DRY-RUN ativado — nenhuma alteração será gravada.');
        }

        // ── Resolve lista de UFs ──────────────────────────────────────────
        $requestedUfs = array_map('strtoupper', $input->getOption('uf'));

        if ($requestedUfs !== []) {
            $ufs = $requestedUfs;
        } else {
            $ufs = $this->stateRepository->findAllUfs();
            if ($ufs === []) {
                $io->warning('Nenhum estado na tabela brazilian_state. Rode: php bin/console app:seed-states');
                return Command::FAILURE;
            }
        }

        $linkMapReferencia = $skipWaze ? [] : $this->stateRepository->findLinkMapReferencia();

        $io->note([
            'Estados    : ' . implode(', ', $ufs),
            'JSON local : ' . ($jsonFile  ?? '—'),
            'JSON URL   : ' . ($jsonUrl   ?? '—'),
            'RBMLQ      : ' . ($skipRbmlq ? 'PULADO (--skip-rbmlq)' : 'ATIVO'),
            'Etapa Waze : ' . ($skipWaze  ? 'PULADA (--skip-waze)' : 'ATIVA (' . count($linkMapReferencia) . ' estado(s) com URL)'),
        ]);

        $allErrors = [];

        // ════════════════════════════════════════════════════════════
        // ETAPA 1A — JSON RBMLQ (padrão, se não pulado)
        // ════════════════════════════════════════════════════════════
        if (!$skipRbmlq && $jsonFile === null && $jsonUrl === null) {
            $io->section('Etapa 1A — Importando medidores RBMLQ (JSON)');

            $total  = count($ufs);
            $ok     = 0;
            $errors = [];

            $io->progressStart($total);

            foreach ($ufs as $uf) {
                try {
                    if (!$dryRun) {
                        ($this->rbmlqHandler)(new ImportRadarMedidoresMessage($uf));
                    }
                    $ok++;
                } catch (\Throwable $e) {
                    $errors[$uf] = $e->getMessage();
                } finally {
                    $io->progressAdvance();
                }
            }

            $io->progressFinish();

            if ($errors !== []) {
                foreach ($errors as $uf => $msg) {
                    $io->text(sprintf('  <comment>%s</comment>: %s', $uf, $msg));
                    $allErrors["[RBMLQ/{$uf}]"] = $msg;
                }
            }

            $io->success(sprintf('%d/%d estado(s) importados via JSON RBMLQ.', $ok, $total));
        }

        // ════════════════════════════════════════════════════════════
        // ETAPA 1B — JSON local ou remoto de radares
        // ════════════════════════════════════════════════════════════
        if ($jsonFile !== null || $jsonUrl !== null) {
            $io->section('Etapa 1B — Importando radares via JSON ' . ($jsonFile ? 'local' : 'remoto'));

            try {
                $filePath = $jsonFile ?? $this->downloadToTempFile((string) $jsonUrl, 'JSON-remoto');
                $isTemp   = ($jsonFile === null);

                try {
                    [$inserted, $updated, $skipped] = $this->importFromJsonFile($filePath, $ufs, $dryRun, $io);
                    $io->success(sprintf(
                        'JSON: %d inseridos | %d atualizados | %d pulados (deduplicate).',
                        $inserted, $updated, $skipped
                    ));
                } finally {
                    if ($isTemp) {
                        @unlink($filePath);
                    }
                }
            } catch (\Throwable $e) {
                $allErrors['[JSON]'] = $e->getMessage();
                $io->error('Erro na etapa JSON: ' . $e->getMessage());
            }
        }

        // ════════════════════════════════════════════════════════════
        // ETAPA 2 — Links Waze via Referencia.UF (ÚNICA planilha)
        // ════════════════════════════════════════════════════════════
        if (!$skipWaze) {
            $io->section('Etapa 2 — Links Waze via Referencia.UF (CSV)');

            $wazeOk   = 0;
            $wazeSkip = 0;

            foreach ($ufs as $uf) {
                $referenciaUrl = $linkMapReferencia[$uf] ?? null;

                if ($referenciaUrl === null) {
                    $io->text(sprintf(
                        '  <comment>[%s]</comment> link_referencia_radares não configurado — pulando.',
                        $uf
                    ));
                    $wazeSkip++;
                    continue;
                }

                try {
                    [$cnt, $matched, $notMatched] = $this->importLinksWaze($uf, $referenciaUrl, $dryRun);
                    $io->text(sprintf(
                        '  <info>[%s]</info> %d link(s) salvos | %d match(es) | %d série(s) sem match.',
                        $uf, $cnt, $matched, $notMatched
                    ));
                    $wazeOk++;
                } catch (\Throwable $e) {
                    $allErrors["[Waze/{$uf}]"] = $e->getMessage();
                    $io->text(sprintf('  <comment>[%s]</comment> Erro: %s', $uf, $e->getMessage()));
                }
            }

            $io->text(sprintf(
                '<info>✔ Etapa 2: %d processado(s), %d pulado(s) sem URL, %d erro(s).</info>',
                $wazeOk, $wazeSkip, count(array_filter($allErrors, fn($k) => str_starts_with($k, '[Waze/'), ARRAY_FILTER_USE_KEY))
            ));
        }

        // ════════════════════════════════════════════════════════════
        // BACKFILL — data_verificacao_efetiva
        // ════════════════════════════════════════════════════════════
        if (!$dryRun) {
            $io->section('Backfill: calculando data_verificacao_efetiva ausente...');

            $affected1 = (int) $this->connection->executeStatement(
                "UPDATE radar_medidor
                 SET    data_verificacao_efetiva = data_ultima_verificacao
                 WHERE  data_verificacao_efetiva IS NULL
                   AND  data_ultima_verificacao  IS NOT NULL
                   AND  data_ultima_verificacao  <> ''"
            );

            $affected2 = (int) $this->connection->executeStatement(
                "UPDATE radar_medidor
                 SET    data_verificacao_efetiva = DATE_FORMAT(
                            DATE_SUB(
                                STR_TO_DATE(data_validade, '%d/%m/%Y'),
                                INTERVAL 1 YEAR
                            ),
                            '%d/%m/%Y'
                        )
                 WHERE  data_verificacao_efetiva IS NULL
                   AND  data_validade IS NOT NULL
                   AND  data_validade <> ''
                   AND  STR_TO_DATE(data_validade, '%d/%m/%Y') IS NOT NULL"
            );

            $totalBackfill = $affected1 + $affected2;

            if ($totalBackfill > 0) {
                $io->success(sprintf(
                    'Backfill: %d registro(s) atualizados (verificacao=%d  validade-1ano=%d).',
                    $totalBackfill, $affected1, $affected2
                ));
            } else {
                $io->text('<info>✔ Nenhum registro pendente de backfill.</info>');
            }
        }

        // ════════════════════════════════════════════════════════════
        // RESULTADO FINAL
        // ════════════════════════════════════════════════════════════
        if ($allErrors !== []) {
            $io->section('Resumo dos erros');
            foreach ($allErrors as $ctx => $msg) {
                $io->text(sprintf('  <comment>%s</comment> %s', $ctx, $msg));
            }
            $io->error(sprintf('%d erro(s) durante a importação.', count($allErrors)));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    // ════════════════════════════════════════════════════════════════
    // ETAPA 1B — importação de JSON de radares
    // ════════════════════════════════════════════════════════════════

    /**
     * Importa array de radares de um arquivo JSON.
     *
     * Formatos suportados:
     *   [ { ...radar }, ... ]
     *   { "radares": [ { ...radar }, ... ] }
     *   { "radares": [...], "wazeLinks": [...] }
     *
     * Campos do objeto radar:
     *   sigla_uf, municipio, logradouro, tipo_medidor, numero_serie,
     *   cnpj_responsavel, data_validade, data_ultima_verificacao,
     *   link_waze, faixas (array de {numero_serie, velocidade, ...})
     *
     * Deduplicação (3 camadas):
     *   1. numero_serie em radar_faixa
     *   2. identity_hash = sha256(UF|local_normalizado|tipo_medidor)
     *   3. UF + UPPER(municipio) + UPPER(logradouro)
     *
     * @return array{int, int, int} [inserted, updated, skipped]
     */
    private function importFromJsonFile(string $path, array $filterUfs, bool $dryRun, SymfonyStyle $io): array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException("Não foi possível ler o arquivo: {$path}");
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        // Resolve formato
        $radares = [];
        if (isset($decoded['radares']) && is_array($decoded['radares'])) {
            $radares = $decoded['radares'];
        } elseif (array_is_list($decoded)) {
            $radares = $decoded;
        }

        // WazeLinks separados (formato { radares: [], wazeLinks: [] })
        $wazeLinks = [];
        if (isset($decoded['wazeLinks']) && is_array($decoded['wazeLinks'])) {
            foreach ($decoded['wazeLinks'] as $wl) {
                $serie = trim((string) ($wl['numero_serie'] ?? $wl['serie'] ?? ''));
                $link  = trim((string) ($wl['link']  ?? $wl['link_waze'] ?? ''));
                if ($serie !== '' && $link !== '') {
                    $wazeLinks[$serie] = $link;
                }
            }
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        $filterUfsNorm = array_map('strtoupper', $filterUfs);

        foreach ($radares as $item) {
            $siglaUf  = strtoupper(trim((string) ($item['sigla_uf'] ?? $item['uf'] ?? '')));
            $municipio   = trim((string) ($item['municipio']   ?? $item['cidade']    ?? ''));
            $logradouro  = trim((string) ($item['logradouro']  ?? $item['local']     ?? $item['local_instalacao'] ?? ''));
            $tipoMedidor = trim((string) ($item['tipo_medidor'] ?? $item['tipo']     ?? ''));
            $numeroSerie = trim((string) ($item['numero_serie'] ?? $item['serie']    ?? ''));
            $cnpj        = trim((string) ($item['cnpj_responsavel'] ?? $item['cnpj'] ?? ''));
            $dataValidade = trim((string) ($item['data_validade'] ?? ''));
            $dataVerif   = trim((string) ($item['data_ultima_verificacao'] ?? ''));
            $linkWaze    = trim((string) ($item['link_waze'] ?? $item['link'] ?? ''));
            $faixas      = is_array($item['faixas'] ?? null) ? $item['faixas'] : [];

            // Filtro de UF
            if ($filterUfsNorm !== [] && $siglaUf !== '' && !in_array($siglaUf, $filterUfsNorm, true)) {
                $skipped++;
                continue;
            }

            if ($logradouro === '' && $municipio === '') {
                $skipped++;
                continue;
            }

            // ── Deduplicação camada 1: numero_serie ──────────────────
            if ($numeroSerie !== '') {
                $existingId = $this->connection->fetchOne(
                    'SELECT rf.radar_medidor_id
                     FROM   radar_faixa  rf
                     JOIN   radar_medidor rm ON rm.id = rf.radar_medidor_id
                     WHERE  rf.numero_serie = ?'
                     . ($siglaUf !== '' ? ' AND rm.sigla_uf = ?' : ''),
                    $siglaUf !== '' ? [$numeroSerie, $siglaUf] : [$numeroSerie]
                );

                if ($existingId !== false) {
                    // UPDATE: atualiza campos que vieram no JSON
                    $updated += $this->updateRadarMedidor((int) $existingId, [
                        'municipio'                  => $municipio,
                        'logradouro'                 => $logradouro,
                        'tipo_medidor'               => $tipoMedidor,
                        'cnpj_responsavel'           => $cnpj,
                        'data_validade'              => $dataValidade,
                        'data_ultima_verificacao'    => $dataVerif,
                        'link_waze'                  => $linkWaze ?: ($wazeLinks[$numeroSerie] ?? ''),
                    ], $dryRun);
                    continue;
                }
            }

            // ── Deduplicação camada 2: identity_hash ─────────────────
            $localNorm   = $this->normalizeKey($logradouro . ' ' . $municipio);
            $hashInput   = strtoupper($siglaUf) . '|' . $localNorm . '|' . $this->normalizeKey($tipoMedidor);
            $identHash   = hash('sha256', $hashInput);

            $existingById = $this->connection->fetchOne(
                'SELECT id FROM radar_medidor WHERE identity_hash = ? LIMIT 1',
                [$identHash]
            );

            if ($existingById !== false) {
                $updated += $this->updateRadarMedidor((int) $existingById, [
                    'municipio'               => $municipio,
                    'logradouro'              => $logradouro,
                    'tipo_medidor'            => $tipoMedidor,
                    'cnpj_responsavel'        => $cnpj,
                    'data_validade'           => $dataValidade,
                    'data_ultima_verificacao' => $dataVerif,
                    'link_waze'               => $linkWaze ?: ($wazeLinks[$numeroSerie] ?? ''),
                ], $dryRun);
                continue;
            }

            // ── Deduplicação camada 3: UF + municipio + logradouro ───
            if ($siglaUf !== '' && $municipio !== '' && $logradouro !== '') {
                $existingByText = $this->connection->fetchOne(
                    'SELECT id FROM radar_medidor
                     WHERE sigla_uf = ?
                       AND UPPER(municipio)  = ?
                       AND UPPER(logradouro) = ?
                     LIMIT 1',
                    [$siglaUf, strtoupper($municipio), strtoupper($logradouro)]
                );

                if ($existingByText !== false) {
                    $updated += $this->updateRadarMedidor((int) $existingByText, [
                        'municipio'               => $municipio,
                        'logradouro'              => $logradouro,
                        'tipo_medidor'            => $tipoMedidor,
                        'cnpj_responsavel'        => $cnpj,
                        'data_validade'           => $dataValidade,
                        'data_ultima_verificacao' => $dataVerif,
                        'link_waze'               => $linkWaze ?: ($wazeLinks[$numeroSerie] ?? ''),
                    ], $dryRun);
                    continue;
                }
            }

            // ── INSERT novo radar ─────────────────────────────────────
            if (!$dryRun) {
                $linkFinal = $linkWaze ?: ($wazeLinks[$numeroSerie] ?? '');

                $this->connection->executeStatement(
                    'INSERT INTO radar_medidor
                        (sigla_uf, municipio, logradouro, tipo_medidor, cnpj_responsavel,
                         data_validade, data_ultima_verificacao, link_waze, identity_hash, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [$siglaUf, $municipio, $logradouro, $tipoMedidor, $cnpj,
                     $dataValidade, $dataVerif, $linkFinal, $identHash]
                );

                $newId = (int) $this->connection->lastInsertId();

                // INSERT faixas
                if ($newId > 0 && $faixas !== []) {
                    foreach ($faixas as $faixa) {
                        $faixaSerie = trim((string) ($faixa['numero_serie'] ?? $faixa['serie'] ?? ''));
                        $velocidade = (int) ($faixa['velocidade'] ?? $faixa['vel'] ?? 0);

                        if ($faixaSerie === '') {
                            continue;
                        }

                        $this->connection->executeStatement(
                            'INSERT IGNORE INTO radar_faixa
                                (radar_medidor_id, numero_serie, velocidade, created_at, updated_at)
                             VALUES (?, ?, ?, NOW(), NOW())',
                            [$newId, $faixaSerie, $velocidade]
                        );
                    }
                } elseif ($newId > 0 && $numeroSerie !== '') {
                    // Se não há array faixas mas tem numero_serie no objeto raiz
                    $this->connection->executeStatement(
                        'INSERT IGNORE INTO radar_faixa
                            (radar_medidor_id, numero_serie, velocidade, created_at, updated_at)
                         VALUES (?, ?, 0, NOW(), NOW())',
                        [$newId, $numeroSerie]
                    );
                }
            }

            $inserted++;
        }

        return [$inserted, $updated, $skipped];
    }

    /**
     * Atualiza campos não-nulos/não-vazios de um radar_medidor existente.
     * Retorna 1 se houve alteração, 0 se nada mudou.
     */
    private function updateRadarMedidor(int $id, array $fields, bool $dryRun): int
    {
        $sets   = [];
        $params = [];

        foreach ($fields as $col => $val) {
            if ($val !== '') {
                $sets[]   = "{$col} = ?";
                $params[] = $val;
            }
        }

        if ($sets === [] || $dryRun) {
            return 0;
        }

        $sets[]   = 'updated_at = NOW()';
        $params[] = $id;

        return (int) $this->connection->executeStatement(
            'UPDATE radar_medidor SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }

    // ════════════════════════════════════════════════════════════════
    // ETAPA 2 — Links Waze via Referencia.UF (ÚNICA planilha aceita)
    // ════════════════════════════════════════════════════════════════

    private function importLinksWaze(string $uf, string $url, bool $dryRun): array
    {
        $tmpFile = $this->downloadToTempFile($url, $uf);

        try {
            return $this->processReferenciaSheet($tmpFile, $uf, $dryRun);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Lê a Referencia.UF (CSV) e cruza com radar_faixa.numero_serie.
     * Campos esperados: LINK: | Nº DE SÉRIE: | CIDADE: | USUÁRIO: | VERIFICADO: | ALTERADO: | AÇÃO:
     * Campos NOVO: e EXPIRADO: são ignorados (sem coluna correspondente).
     *
     * @return array{int, int, int} [updated, matched, notMatched]
     */
    private function processReferenciaSheet(string $path, string $uf, bool $dryRun): array
    {
        $fh = fopen($path, 'rb');

        if ($fh === false) {
            throw new \RuntimeException("Não foi possível abrir arquivo temporário para {$uf}");
        }

        // Remove BOM UTF-8 se presente
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        // Detecta cabeçalho (primeira linha com AMBAS as colunas LINK e Nº DE SÉRIE)
        $header         = null;
        $colLink        = false;
        $colSerie       = false;
        $attempts       = 0;
        $lastNormalized = [];

        while ($attempts < 20) {
            $rawRow = fgetcsv($fh, 0, ',', '"', '');

            if ($rawRow === false) {
                break;
            }

            $attempts++;

            if ($rawRow === null || $rawRow === [null]) {
                continue;
            }

            $normalized     = array_map(fn($h) => $this->normalizeKey((string) $h), $rawRow);
            $lastNormalized = $normalized;

            $lk = array_search('link',     $normalized, true);
            if ($lk === false) {
                $lk = array_search('linkwaze', $normalized, true);
            }

            $ls = array_search('ndeserie',  $normalized, true);
            if ($ls === false) { $ls = array_search('nodeserie', $normalized, true); }
            if ($ls === false) { $ls = array_search('serie',     $normalized, true); }
            if ($ls === false) { $ls = array_search('noserie',   $normalized, true); }

            if ($lk !== false && $ls !== false) {
                $header   = $normalized;
                $colLink  = $lk;
                $colSerie = $ls;
                break;
            }
        }

        if ($header === null || $colLink === false || $colSerie === false) {
            fclose($fh);
            throw new \RuntimeException(
                "Colunas LINK e Nº DE SÉRIE não encontradas na Referencia.{$uf} " .
                "(verificadas {$attempts} linha(s)). " .
                "Última linha normalizada: [" . implode(', ', $lastNormalized) . "]"
            );
        }

        // Coleta pares série → link (guarda só o primeiro link por série)
        $linkMap = [];

        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($row === null || count($row) <= max((int) $colLink, (int) $colSerie)) {
                continue;
            }

            $link  = trim((string) ($row[$colLink]  ?? ''));
            $serie = trim((string) ($row[$colSerie] ?? ''));

            if ($link === '' || $serie === '') {
                continue;
            }

            $linkMap[$serie] ??= $link;
        }

        fclose($fh);

        if ($linkMap === []) {
            return [0, 0, 0];
        }

        // Match com radar_faixa → radar_medidor e grava link_waze
        $updated    = 0;
        $matched    = 0;
        $notMatched = 0;

        foreach ($linkMap as $serie => $linkWaze) {
            $radarId = $this->connection->fetchOne(
                'SELECT rf.radar_medidor_id
                 FROM   radar_faixa  rf
                 JOIN   radar_medidor rm ON rm.id = rf.radar_medidor_id
                 WHERE  rf.numero_serie = ?
                   AND  rm.sigla_uf    = ?
                 LIMIT  1',
                [$serie, $uf]
            );

            if ($radarId === false) {
                $notMatched++;
                continue;
            }

            $matched++;

            if (!$dryRun) {
                $updated += (int) $this->connection->executeStatement(
                    'UPDATE radar_medidor SET link_waze = ?, updated_at = NOW() WHERE id = ?',
                    [$linkWaze, (int) $radarId]
                );
            }
        }

        return [$updated, $matched, $notMatched];
    }

    // ════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════

    private function downloadToTempFile(string $url, string $context = ''): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'radar_ref_');

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
        fclose($fp);

        if (!$ok || $errCode !== 0 || filesize($tmpPath) < 10) {
            @unlink($tmpPath);
            throw new \RuntimeException("cURL erro {$errCode}: {$errMsg} — [{$context}] URL: {$url}");
        }

        return $tmpPath;
    }

    private function normalizeKey(string $col): string
    {
        $col = mb_strtolower(trim($col), 'UTF-8');
        $col = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $col) ?: $col;
        $col = preg_replace('/[^a-z0-9]/', '', $col) ?? $col;
        return $col;
    }
}
