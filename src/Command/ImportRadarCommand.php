<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ImportRadarGoogleSheetsMessage;
use App\Message\ImportRadarMedidoresMessage;
use App\MessageHandler\ImportRadarGoogleSheetsHandler;
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
 * Command UNIFICADO de importação de radares.
 *
 * ════════════════════════════════════════════════════════════
 * ETAPA 1 — Importa dados técnicos (medidores RBMLQ)
 * ════════════════════════════════════════════════════════════
 *   Fonte: Google Sheets CSV (padrão) ou RBMLQ/INMETRO API.
 *   URL por estado: prioridade 1 = banco (brazilian_state.link_base_radares)
 *                   prioridade 2 = UF_GID_MAP hardcoded (fallback)
 *
 * ════════════════════════════════════════════════════════════
 * ETAPA 2 — Importa links Waze (aba REFERENCIA.UF)
 * ════════════════════════════════════════════════════════════
 *   Para cada estado processado, baixa a aba REFERENCIA.<UF>
 *   da mesma planilha e cruza pelo Nº de Série para preencher
 *   radar_medidor.link_waze.
 *
 *   Colunas esperadas na aba REFERENCIA.UF:
 *     LINK | Nº DE SÉRIE | NOVO | EXPIRADO | CIDADE | USUÁRIO | VERIFICADO | ALTERADO | AÇÃO
 *
 * ════════════════════════════════════════════════════════════
 * BACKFILL AUTOMÁTICO
 * ════════════════════════════════════════════════════════════
 *   Preenche data_verificacao_efetiva = NULL ao final.
 *
 * ════════════════════════════════════════════════════════════
 * USO
 * ════════════════════════════════════════════════════════════
 *   php bin/console app:import-radar                      # todos os estados
 *   php bin/console app:import-radar --uf=SP              # só SP
 *   php bin/console app:import-radar --uf=SP --uf=RJ
 *   php bin/console app:import-radar --skip-waze          # pula etapa 2
 *   php bin/console app:import-radar --source=rbmlq       # usa API INMETRO
 */
#[AsCommand(
    name: 'app:import-radar',
    description: 'Importa radares (medidores + links Waze) para todos os estados',
    aliases: ['app:import-radar-medidores'],  // mantém alias do command antigo
)]
final class ImportRadarCommand extends Command
{
    /**
     * GID das abas REFERENCIA por estado na planilha Google Sheets.
     * Preencha conforme descobrir os gids das abas REFERENCIA.
     */
    private const REFERENCIA_GID_MAP = [
        'AC' => '',  // preencher quando disponível
        'AL' => '',
        'AM' => '',
        'AP' => '',
        'BA' => '',
        'CE' => '',
        'DF' => '',
        'ES' => '',
        'GO' => '',
        'MA' => '',
        'MG' => '',
        'MS' => '',
        'MT' => '',
        'PA' => '',
        'PB' => '',
        'PE' => '',
        'PI' => '',
        'PR' => '',
        'RJ' => '',
        'RN' => '',
        'RO' => '',
        'RR' => '',
        'RS' => '',
        'SC' => '',
        'SE' => '',
        'SP' => '',
        'TO' => '',
    ];

    private const CURL_TIMEOUT = 120;

    public function __construct(
        private readonly ImportRadarMedidoresHandler    $rbmlqHandler,
        private readonly ImportRadarGoogleSheetsHandler $sheetsHandler,
        private readonly BrazilianStateRepository       $stateRepository,
        private readonly Connection                     $connection,
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
                'source', null,
                InputOption::VALUE_REQUIRED,
                'Fonte dos medidores: "sheets" (padrão) ou "rbmlq"',
                'sheets'
            )
            ->addOption(
                'skip-waze', null,
                InputOption::VALUE_NONE,
                'Pula a importação de links Waze (etapa 2)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $source = strtolower((string) $input->getOption('source'));
        $useSheets  = $source !== 'rbmlq';
        $skipWaze   = (bool) $input->getOption('skip-waze');

        $io->title('Importação Radares — Unificada');

        // ── Resolve lista de UFs ──────────────────────────────────────────────
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

        // ── Links customizados do banco (para fonte sheets) ───────────────────
        $linkMap = $useSheets ? $this->stateRepository->findLinkMapRadares() : [];

        $fonte = $useSheets ? 'Google Sheets CSV' : 'RBMLQ/INMETRO API';
        $io->note([
            'Fonte         : ' . $fonte,
            'Estados       : ' . implode(', ', $ufs),
            'Links no BD   : ' . count($linkMap) . ' estado(s) com URL personalizada',
            'Etapa Waze    : ' . ($skipWaze ? 'PULADA (--skip-waze)' : 'ATIVA'),
        ]);

        // ════════════════════════════════════════════════════════════
        // ETAPA 1 — Importa medidores
        // ════════════════════════════════════════════════════════════
        $io->section('Etapa 1 — Importando medidores RBMLQ');

        $total  = count($ufs);
        $ok     = 0;
        $errors = [];

        $io->progressStart($total);

        foreach ($ufs as $uf) {
            try {
                if ($useSheets) {
                    $customUrl = $linkMap[$uf] ?? null;
                    ($this->sheetsHandler)(new ImportRadarGoogleSheetsMessage($uf, $customUrl));
                } else {
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
            $io->warning(sprintf('%d estado(s) com erro na etapa 1:', count($errors)));
            foreach ($errors as $uf => $msg) {
                $io->text(sprintf('  <comment>%s</comment>: %s', $uf, $msg));
            }
        }

        $io->success(sprintf('%d/%d estado(s) importados via %s.', $ok, $total, $fonte));

        // ════════════════════════════════════════════════════════════
        // ETAPA 2 — Importa links Waze da aba REFERENCIA.UF
        // ════════════════════════════════════════════════════════════
        if (!$skipWaze) {
            $io->section('Etapa 2 — Importando links Waze (aba REFERENCIA.UF)');
            $wazeOk     = 0;
            $wazeErrors = [];

            foreach ($ufs as $uf) {
                $gid = self::REFERENCIA_GID_MAP[$uf] ?? '';

                if ($gid === '') {
                    $io->text(sprintf('  <comment>[%s]</comment> GID da aba REFERENCIA não configurado — pulando.', $uf));
                    continue;
                }

                try {
                    $updated = $this->importLinksWaze($uf, $gid);
                    $io->text(sprintf('  <info>[%s]</info> %d link(s) Waze atualizados.', $uf, $updated));
                    $wazeOk++;
                } catch (\Throwable $e) {
                    $wazeErrors[$uf] = $e->getMessage();
                    $io->text(sprintf('  <comment>[%s]</comment> Erro: %s', $uf, $e->getMessage()));
                }
            }

            if ($wazeErrors !== []) {
                $io->warning(sprintf('%d estado(s) com erro na etapa 2 (links Waze).', count($wazeErrors)));
            } else {
                $io->text(sprintf('<info>✔ Etapa 2 concluída: %d estado(s) processados.</info>', $wazeOk));
            }
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
    // Importa links Waze da aba REFERENCIA.UF
    //
    // Colunas esperadas (linha 1 = cabeçalho):
    //   LINK | Nº DE SÉRIE | NOVO | EXPIRADO | CIDADE | USUÁRIO | VERIFICADO | ALTERADO | AÇÃO
    //
    // Cruza pelo Nº DE SÉRIE com radar_faixa.numero_serie
    // e atualiza radar_medidor.link_waze.
    // ════════════════════════════════════════════════════════════
    private function importLinksWaze(string $uf, string $gid): int
    {
        $baseUrl = ImportRadarGoogleSheetsMessage::BASE_URL;
        $url     = $baseUrl . '&gid=' . $gid;

        $tmpFile = $this->downloadToTempFile($url, $uf);

        try {
            return $this->processReferenciaSheet($tmpFile, $uf);
        } finally {
            @unlink($tmpFile);
        }
    }

    private function processReferenciaSheet(string $path, string $uf): int
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

        // Lê cabeçalho
        $rawHeader = fgetcsv($fh);
        if ($rawHeader === false || $rawHeader === null) {
            fclose($fh);
            throw new \RuntimeException("CSV da aba REFERENCIA.{$uf} sem cabeçalho.");
        }

        $header = array_map(fn($h) => $this->normalizeKey((string) $h), $rawHeader);

        // Descobre índices das colunas LINK e Nº DE SÉRIE
        $colLink  = array_search('link',     $header, true);
        $colSerie = array_search('ndeserie', $header, true);

        // Fallbacks para variações comuns do cabeçalho
        if ($colLink  === false) { $colLink  = array_search('linkwaze', $header, true); }
        if ($colSerie === false) { $colSerie = array_search('serie',    $header, true); }
        if ($colSerie === false) { $colSerie = array_search('noserie',  $header, true); }

        if ($colLink === false || $colSerie === false) {
            fclose($fh);
            throw new \RuntimeException(
                "Colunas LINK/Nº DE SÉRIE não encontradas na aba REFERENCIA.{$uf}. " .
                "Cabeçalho detectado: " . implode(', ', $header)
            );
        }

        // Monta mapa série => link
        $linkMap = [];

        while (($row = fgetcsv($fh)) !== false) {
            if ($row === null || count($row) <= max((int)$colLink, (int)$colSerie)) {
                continue;
            }

            $link  = trim((string) ($row[$colLink]  ?? ''));
            $serie = trim((string) ($row[$colSerie] ?? ''));

            if ($link === '' || $serie === '') {
                continue;
            }

            $linkMap[$serie] = $link;
        }

        fclose($fh);

        if ($linkMap === []) {
            return 0;
        }

        // Cruza numero_serie da radar_faixa com radar_medidor
        // UPDATE radar_medidor via JOIN com radar_faixa pelo numero_serie
        $updated = 0;

        foreach ($linkMap as $serie => $linkWaze) {
            // Busca o radar_medidor_id pelo numero_serie na radar_faixa
            $radarId = $this->connection->fetchOne(
                'SELECT rf.radar_medidor_id
                 FROM   radar_faixa rf
                 JOIN   radar_medidor rm ON rm.id = rf.radar_medidor_id
                 WHERE  rf.numero_serie = ?
                   AND  rm.sigla_uf    = ?
                 LIMIT  1',
                [$serie, $uf]
            );

            if ($radarId === false) {
                continue;
            }

            $rows = (int) $this->connection->executeStatement(
                'UPDATE radar_medidor SET link_waze = ? WHERE id = ?',
                [$linkWaze, (int) $radarId]
            );

            $updated += $rows;
        }

        return $updated;
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
        curl_close($ch);
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
