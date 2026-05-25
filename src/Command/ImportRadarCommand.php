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
 *   Fonte: Google Sheets CSV/TSV (padrão) ou RBMLQ/INMETRO API.
 *   URL por estado: prioridade 1 = banco (brazilian_state.link_base_radares)
 *                   prioridade 2 = UF_GID_MAP hardcoded (fallback)
 *
 * ════════════════════════════════════════════════════════════
 * ETAPA 2 — Importa links Waze (aba REFERENCIA.UF)
 * ════════════════════════════════════════════════════════════
 *   Fonte: banco (brazilian_state.link_referencia_radares)
 *   Cruza: REFERENCIA.Nº DE SÉRIE = radar_faixa.numero_serie
 *   Grava: radar_medidor.link_waze
 *
 *   Colunas esperadas na aba REFERENCIA.UF:
 *     LINK: | Nº DE SÉRIE: | NOVO: | EXPIRADO: | CIDADE: | USUÁRIO: | VERIFICADO: | ALTERADO: | AÇÃO:
 *
 *   Aliases aceitos para LINK (após normalização):
 *     link, linkwaze
 *
 *   Aliases aceitos para Nº DE SÉRIE (após normalização):
 *     ndeserie, nodeserie, serie, noserie
 *
 *   As primeiras linhas podem conter metadados (ex: totais, percentuais).
 *   O cabeçalho real é detectado automaticamente: primeira linha que
 *   contenha AMBAS as colunas (link E série) simultaneamente.
 *
 * ════════════════════════════════════════════════════════════
 * BACKFILL AUTOMÁTICO
 * ════════════════════════════════════════════════════════════
 *   Preenche data_verificacao_efetiva = NULL ao final.
 *
 * ════════════════════════════════════════════════════════════
 * USO
 * ════════════════════════════════════════════════════════════
 *   php bin/console app:import-radares                    # todos os estados
 *   php bin/console app:import-radares --uf=SP            # só SP
 *   php bin/console app:import-radares --uf=SP --uf=RJ
 *   php bin/console app:import-radares --skip-waze        # pula etapa 2
 *   php bin/console app:import-radares --source=rbmlq     # usa API INMETRO
 */
#[AsCommand(
    name: 'app:import-radares',
    description: 'Importa radares (medidores + links Waze) para todos os estados',
)]
final class ImportRadarCommand extends Command
{
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
        $io        = new SymfonyStyle($input, $output);
        $source    = strtolower((string) $input->getOption('source'));
        $useSheets = $source !== 'rbmlq';
        $skipWaze  = (bool) $input->getOption('skip-waze');

        $io->title('Importação Radares — Unificada');

        // ── Resolve lista de UFs ────────────────────────────────────────
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

        // ── Links do banco ────────────────────────────────────────────
        $linkMapRadares    = $useSheets ? $this->stateRepository->findLinkMapRadares() : [];
        $linkMapReferencia = $skipWaze  ? [] : $this->stateRepository->findLinkMapReferencia();

        $fonte = $useSheets ? 'Google Sheets CSV/TSV' : 'RBMLQ/INMETRO API';
        $io->note([
            'Fonte           : ' . $fonte,
            'Estados         : ' . implode(', ', $ufs),
            'Links radares   : ' . count($linkMapRadares) . ' estado(s) com URL personalizada',
            'Links referencia: ' . count($linkMapReferencia) . ' estado(s) com URL de referência',
            'Etapa Waze      : ' . ($skipWaze ? 'PULADA (--skip-waze)' : 'ATIVA'),
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
                    $customUrl = $linkMapRadares[$uf] ?? null;
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
            $wazeSkip   = 0;

            foreach ($ufs as $uf) {
                $referenciaUrl = $linkMapReferencia[$uf] ?? null;

                if ($referenciaUrl === null) {
                    $io->text(sprintf(
                        '  <comment>[%s]</comment> link_referencia_radares não configurado — pulando.' .
                        ' (Configure em: EasyAdmin → Estado → "%s")',
                        $uf, $uf
                    ));
                    $wazeSkip++;
                    continue;
                }

                try {
                    $updated = $this->importLinksWaze($uf, $referenciaUrl);
                    $io->text(sprintf('  <info>[%s]</info> %d link(s) Waze atualizados.', $uf, $updated));
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
    // Importa links Waze da aba REFERENCIA.UF
    // ════════════════════════════════════════════════════════════

    private function importLinksWaze(string $uf, string $url): int
    {
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

        // ── Detecta o cabeçalho real ──────────────────────────────────
        //
        // A planilha REFERENCIA.UF tem metadados nas primeiras linhas:
        //   Linha 1: ALAGOAS,,,,,,,,
        //   Linha 2: ATIVO:,73,CADASTRADO:,69,,,,,
        //   Linha 3: PORCENTAGEM:,"111,6883117",DELETAR:,43,,,,,
        //   Linha 4: REPROVADO:,0,LINK INVÁLIDO:,0,,,,,
        //   Linha 5: LINK:,Nº DE SÉRIE:,NOVO:,...   ← cabeçalho real
        //
        // REGRA: aceita APENAS a linha que contenha simultaneamente
        //   a coluna LINK  E  a coluna Nº DE SÉRIE (AND, não OR).
        // Isso evita falso-positivo em linhas de metadado que por
        // acaso contenham uma das duas palavras isoladamente.
        //
        // Aliases aceitos após normalizeKey():
        //   LINK      → link, linkwaze
        //   Nº DE SÉRIE → ndeserie, nodeserie, serie, noserie
        $header       = null;
        $colLink      = false;
        $colSerie     = false;
        $attempts     = 0;
        $lastNormalized = [];

        while ($attempts < 20) {
            // PHP 8.5: $escape deve ser explícito — '' desativa escape (RFC 4180)
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

            // Busca coluna LINK
            $lk = array_search('link',     $normalized, true);
            if ($lk === false) { $lk = array_search('linkwaze', $normalized, true); }

            // Busca coluna Nº DE SÉRIE (vários aliases)
            $ls = array_search('ndeserie', $normalized, true);
            if ($ls === false) { $ls = array_search('nodeserie', $normalized, true); }
            if ($ls === false) { $ls = array_search('serie',     $normalized, true); }
            if ($ls === false) { $ls = array_search('noserie',   $normalized, true); }

            // *** AND: só aceita quando AMBAS as colunas estão presentes ***
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
                "Colunas LINK e Nº DE SÉRIE não encontradas juntas na aba REFERENCIA.{$uf} " .
                "(verificadas {$attempts} linha(s)). " .
                "Aliases de link aceitos: link, linkwaze. " .
                "Aliases de série aceitos: ndeserie, nodeserie, serie, noserie. " .
                "Última linha normalizada: [" . implode(', ', $lastNormalized) . "]"
            );
        }

        $linkMap = [];

        // PHP 8.5: $escape deve ser explícito — '' desativa escape (RFC 4180)
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($row === null || count($row) <= max((int) $colLink, (int) $colSerie)) {
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

        $updated = 0;

        foreach ($linkMap as $serie => $linkWaze) {
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

            $updated += (int) $this->connection->executeStatement(
                'UPDATE radar_medidor SET link_waze = ? WHERE id = ?',
                [$linkWaze, (int) $radarId]
            );
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
        // PHP 8.5: curl_close() não tem mais efeito
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
