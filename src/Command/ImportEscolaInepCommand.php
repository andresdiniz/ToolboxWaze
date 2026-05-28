<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ImportEscolaInepMessage;
use App\MessageHandler\ImportEscolaInepHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa escolas do Censo Escolar INEP a partir de planilha Google Sheets.
 *
 * A URL do CSV é lida de ESCOLA_INEP_CSV_URL no .env.
 * Pode ser sobrescrita via --url.
 *
 * Uso:
 *   php bin/console app:import-escola-inep
 *   php bin/console app:import-escola-inep --url="https://..."
 */
#[AsCommand(
    name: 'app:import-escola-inep',
    description: 'Importa escolas INEP/MEC de planilha Google Sheets (todos os estados)',
)]
final class ImportEscolaInepCommand extends Command
{
    public function __construct(
        private readonly ImportEscolaInepHandler $handler,
        private readonly string $escolaInepCsvUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'url',
            null,
            InputOption::VALUE_REQUIRED,
            'URL do CSV (substitui ESCOLA_INEP_CSV_URL do .env)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $url = $input->getOption('url') ?: $this->escolaInepCsvUrl;

        if (empty($url)) {
            $io->error('URL não definida. Configure ESCOLA_INEP_CSV_URL no .env ou use --url.');
            return Command::FAILURE;
        }

        $io->title('Importação de Escolas INEP (Google Sheets)');
        $io->text('URL: ' . $url);
        $io->newLine();

        $start = microtime(true);

        try {
            ($this->handler)(new ImportEscolaInepMessage($url));
            $elapsed = round(microtime(true) - $start, 1);
            $io->success("Importação concluída em {$elapsed}s.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $elapsed = round(microtime(true) - $start, 1);
            $io->error("Falha após {$elapsed}s: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
