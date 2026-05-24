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
 * Itera os 27 estados e importa radares via handler direto.
 *
 * ════════════════════════════════════════════════════════════
 * CHAVE DE FONTE — USE_GOOGLE_SHEETS_SOURCE
 * ════════════════════════════════════════════════════════════
 *
 *   true  → Google Sheets CSV  (nova fonte)
 *   false → API RBMLQ/INMETRO  (fonte original — todos os campos)
 *
 * ════════════════════════════════════════════════════════════
 * URL POR ESTADO (prioridade decrescente)
 * ════════════════════════════════════════════════════════════
 *
 *   1. BrazilianState::linkBaseRadares (banco de dados)
 *      → gerenciável pelo admin sem deploy.
 *
 *   2. ImportRadarGoogleSheetsMessage::UF_GID_MAP (fallback hardcoded)
 *      → para estados sem link no banco.
 *
 * ════════════════════════════════════════════════════════════
 * BACKFILL AUTOMÁTICO
 * ════════════════════════════════════════════════════════════
 *
 * Ao final de cada execução (independente da fonte), o command
 * percorre todos os registros com data_verificacao_efetiva = NULL
 * e preenche automaticamente usando:
 *
 *   1. data_ultima_verificacao  (se preenchida)
 *   2. data_validade - 1 ano    (se data_ultima_verificacao vazia)
 *
 * ════════════════════════════════════════════════════════════
 * USO
 * ════════════════════════════════════════════════════════════
 *
 *   php bin/console app:import-radar-medidores           # todos os estados
 *   php bin/console app:import-radar-medidores --uf=SP   # só São Paulo
 *   php bin/console app:import-radar-medidores --uf=SP --uf=RJ
 */
#[AsCommand(
    name: 'app:import-radar-medidores',
    description: 'Importa medidores de todos os estados (RBMLQ ou Google Sheets)',
)]
final class ImportRadarMedidoresCommand extends Command
{
    /**
     * ⚙️  TROQUE AQUI PARA MUDAR A FONTE DE DADOS
     *
     * true  → Google Sheets CSV  (nova fonte)
     * false → API RBMLQ/INMETRO  (fonte original)
     */
    private const USE_GOOGLE_SHEETS_SOURCE = true;

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
        $this->addOption(
            'uf',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Filtra por UF(s) específica(s). Ex: --uf=SP --uf=RJ',
            []
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fonte = self::USE_GOOGLE_SHEETS_SOURCE
            ? 'Google Sheets CSV'
            : 'RBMLQ/INMETRO API';

        $io->title(sprintf('Importação Radares — Fonte: %s', $fonte));

        // Carrega mapa de links customizados do banco (UF => URL)
        $linkMap = self::USE_GOOGLE_SHEETS_SOURCE
            ? $this->stateRepository->findLinkMapRadares()
            : [];

        if (self::USE_GOOGLE_SHEETS_SOURCE) {
            $io->note([
                'Fonte ativa  : Google Sheets CSV',
                'Preservados  : estado, proprietario_*, historico_json',
                'Links no BD  : ' . count($linkMap) . ' estado(s) com URL personalizada',
                'URL fallback : ' . ImportRadarGoogleSheetsMessage::BASE_URL,
                'Para trocar  : altere USE_GOOGLE_SHEETS_SOURCE = false neste arquivo.',
            ]);
        } else {
            $io->note([
                'Fonte ativa : RBMLQ/INMETRO API (fonte original)',
                'URL base    : ' . ImportRadarMedidoresMessage::BASE_URL,
                'Para trocar : altere USE_GOOGLE_SHEETS_SOURCE = true neste arquivo.',
            ]);
        }

        $requestedUfs = array_map('strtoupper', $input->getOption('uf'));

        if ($requestedUfs !== []) {
            $ufs = $requestedUfs;
        } else {
            $ufs = $this->stateRepository->findAllUfs();

            if ($ufs === []) {
                $io->warning(
                    'Nenhum estado encontrado na tabela brazilian_state. ' .
                    'Rode primeiro: php bin/console doctrine:fixtures:load'
                );
                return Command::FAILURE;
            }
        }

        $total  = count($ufs);
        $ok     = 0;
        $errors = [];

        $io->text(sprintf('Estados a processar: <info>%s</info>', implode(', ', $ufs)));
        $io->newLine();
        $io->progressStart($total);

        foreach ($ufs as $uf) {
            try {
                if (self::USE_GOOGLE_SHEETS_SOURCE) {
                    // Prioridade 1: link personalizado do banco
                    // Prioridade 2: fallback do UF_GID_MAP (dentro do getUrl())
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

        // ════════════════════════════════════════════════════════════
        // BACKFILL: preenche data_verificacao_efetiva nos registros
        // que ainda estão com NULL após a importação.
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
                'Backfill concluído: %d registro(s) atualizados (data_verificacao = %d, validade-1ano = %d).',
                $totalBackfill,
                $affected1,
                $affected2
            ));
        } else {
            $io->text('<info>✔ Nenhum registro pendente de backfill.</info>');
        }

        if ($errors !== []) {
            $io->warning(sprintf('%d estado(s) com erro:', count($errors)));
            foreach ($errors as $uf => $msg) {
                $io->text(sprintf('  <comment>%s</comment>: %s', $uf, $msg));
            }
        }

        $io->success(sprintf(
            '%d/%d estado(s) importado(s) com sucesso via %s.',
            $ok,
            $total,
            $fonte
        ));

        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
