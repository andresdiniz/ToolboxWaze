<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ImportSaudeMessage;
use App\MessageHandler\ImportSaudeHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa estabelecimentos de saúde (CNES) de um CSV.
 *
 * A URL é lida de SAUDE_CNES_CSV_URL no .env (ou --url); também aceita arquivo local via --file.
 *
 * Uso:
 *   php bin/console app:import-saude
 *   php bin/console app:import-saude --url="https://..."
 *   php bin/console app:import-saude --file=/caminho/cnes.csv
 */
#[AsCommand(
    name: 'app:import-saude',
    description: 'Importa estabelecimentos de saúde (CNES) de um CSV (URL ou arquivo local)',
)]
final class ImportSaudeCommand extends Command
{
    public function __construct(
        private readonly ImportSaudeHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'URL do CSV (substitui SAUDE_CNES_CSV_URL do .env)')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Caminho de um CSV local');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fonte = $input->getOption('file')
            ?: $input->getOption('url')
            ?: ($_ENV['SAUDE_CNES_CSV_URL'] ?? $_SERVER['SAUDE_CNES_CSV_URL'] ?? '');

        if (!is_string($fonte) || $fonte === '') {
            $io->error('Informe --file, --url ou configure SAUDE_CNES_CSV_URL no .env.');

            return Command::FAILURE;
        }

        if ($input->getOption('file') && !is_file((string) $input->getOption('file'))) {
            $io->error('Arquivo não encontrado: ' . $input->getOption('file'));

            return Command::FAILURE;
        }

        $io->title('Importação de estabelecimentos de saúde (CNES)');
        $io->text('Fonte: ' . $fonte);

        $inicio = microtime(true);

        try {
            $resultado = ($this->handler)(new ImportSaudeMessage($fonte));
        } catch (\Throwable $e) {
            $io->error(sprintf('Falha após %.1fs: %s', microtime(true) - $inicio, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Importação concluída em %.1fs (%d linhas lidas, %d válidas).',
            microtime(true) - $inicio,
            $resultado['lidas'],
            $resultado['validas'],
        ));

        return Command::SUCCESS;
    }
}
