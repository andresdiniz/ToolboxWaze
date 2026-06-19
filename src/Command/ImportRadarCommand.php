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
 * Importa radares em duas etapas:
 *
 * ETAPA 1 — Medidores RBMLQ/INMETRO via JSON
 *   URL: https://servicos.rbmlq.gov.br/dados-abertos/{UF}/medidores.json
 *
 * ETAPA 2 — Links Waze via planilha Referencia.UF (CSV)
 *   Cruza: REFERENCIA."Nº DE SÉRIE" = radar_faixa.numero_serie
 *   Grava: radar_medidor.link_waze
 *
 *   Colunas esperadas: LINK: | Nº DE SÉRIE: | NOVO: | EXPIRADO: | CIDADE: | USUÁRIO: | VERIFICADO: | ALTERADO: | AÇÃO:
 *
 * BACKFILL — preenche data_verificacao_efetiva = NULL ao final.
 *
 * USO:
 *   php bin/console app:import-radares               # todos os estados
 *   php bin/console app:import-radares --uf=MG       # só MG
 *   php bin/console app:import-radares --uf=SP --uf=RJ
 *   php bin/console app:import-radares --skip-waze   # pula etapa 2
 */
#[AsCommand(
    name: 'app:import-radares',
    description: 'Importa radares: JSON RBMLQ (etapa 1) + links Waze Referencia.UF (etapa 2)',
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
                'skip-waze', null,
                InputOption::VALUE_NONE,
                'Pula a importação de links Waze (etapa 2)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $skipWaze = (bool) $input->getOption('skip-waze');

        $io->title('Importação Radares — JSON RBMLQ');

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
            'Fonte      : RBMLQ/INMETRO JSON',
            'Estados    : ' . implode(', ', $ufs),
            'Etapa Waze : ' . ($skipWaze ? 'PULADA (--skip-waze)' : 'ATIVA (' . count($linkMapReferencia) . ' estado(s) com URL)'),
        ]);

        // ════════════════════════════════════════════════════════════
        // ETAPA 1 — Importa medidores via JSON RBMLQ
        // ════════════════════════════════════════════════════════════
        $io->section('Etapa 1 — Importando medidores RBMLQ (JSON)');

        $total  = count($ufs);
        $ok     = 0;
        $errors = [];

        $io->progressStart($total);

        foreach ($ufs as $uf) {
            try {
                ($this->rbmlqHandler)(new ImportRadarMedidoresMessage($uf));
                $ok++;
            } catch (\Throwable $e) {
                $errors[$uf] = $e->getMessage();
            } finally {
                $io->progressAdvance();
            }
        }

        $io->progressFinish();

        if ($errors !== []) {
            $io->warning(sprintf('%d estado(s) com erro na etapa 1:', count($errors)));
            foreach ($errors as $uf => $msg) {
                $io->text(sprintf('  <comment>%s</comment>: %s', $uf, $msg));
            }
        }

        $io->success(sprintf('%d/%d estado(s) importados via JSON RBMLQ.', $ok, $total));

        // ════════════════════════════════════════════════════════════
        // ETAPA 2 — Links Waze via Referencia.UF (CSV)
        // ════════════════════════════════════════════════════════════
        if (!$skipWaze) {
            $io->section('Etapa 2 — Importando links Waze (Referencia.UF)');

            $wazeOk     = 0;
            $wazeErrors = [];
            $wazeSkip   = 0;

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
                    [$updated, $matched, $notMatched] = $this->importLinksWaze($uf, $referenciaUrl);
                    $io->text(sprintf(
                        '  <info>[%s]</info> %d link(s) salvos | %d match(es) | %d série(s) sem match.',
                        $uf, $updated, $matched, $notMatched
                    ));
                    $wazeOk++;
                } catch (\Throwable $e) {
                    $wazeErrors[$uf] = $e->getMessage();
                    $io->text(sprintf('  <comment>[%s]</comment> Erro: %s', $uf, $e->getMessage()));
                }
            }

            if ($wazeErrors !== []) {
                $io->warning(sprintf('%d estado(s) com erro na etapa 2 (links Waze).', count($wazeErrors)));
            }

            $io->text(sprintf(
                '<info>✔ Etapa 2: %d processado(s), %d pulado(s) sem URL, %d erro(s).</info>',
                $wazeOk, $wazeSkip, count($wazeErrors)
            ));
        }

        // ════════════════════════════════════════════════════════════
        // BACKFILL — data_verificacao_efetiva
        // ════════════════════════════════════════════════════════════
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

        return ($errors === []) ? Command::SUCCESS : Command::FAILURE;
    }

    // ════════════════════════════════════════════════════════════
    // Etapa 2 — helpers internos
    // ════════════════════════════════════════════════════════════

    private function importLinksWaze(string $uf, string $url): array
    {
        $tmpFile = $this->downloadToTempFile($url, $uf);

        try {
            return $this->processReferenciaSheet($tmpFile, $uf);
        } finally {
            @unlink($tmpFile);
        }
    }

    private function processReferenciaSheet(string $path, string $uf): array
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

        // Detecta o cabeçalho real (primeira linha com AMBAS as colunas LINK e Nº DE SÉRIE)
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
                "Colunas LINK e Nº DE SÉRIE não encontradas juntas na Referencia.{$uf} " .
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
            $updated += (int) $this->connection->executeStatement(
                'UPDATE radar_medidor SET link_waze = ? WHERE id = ?',
                [$linkWaze, (int) $radarId]
            );
        }

        return [$updated, $matched, $notMatched];
    }

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
