<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ImportFuelResellersMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Comando para disparar a importação do CSV de revendedores de combustíveis da ANP.
 *
 * Uso:
 *   php bin/console app:import-fuel-resellers
 *   php bin/console app:import-fuel-resellers --url=https://...
 *
 * Para processar a fila em background:
 *   php bin/console messenger:consume async --memory-limit=256M --time-limit=3600
 */
#[AsCommand(
    name: 'app:import-fuel-resellers',
    description: 'Dispara a importação do CSV de revendedores varejistas de combustíveis (ANP)',
)]
final class ImportFuelResellersCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'url',
            null,
            InputOption::VALUE_OPTIONAL,
            'URL do CSV (padrão: URL oficial da ANP)',
            ImportFuelResellersMessage::ANP_URL
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $url = (string) $input->getOption('url');

        $io->title('Importação ANP — Revendedores Varejistas de Combustíveis');
        $io->text(sprintf('URL: %s', $url));

        $this->bus->dispatch(new ImportFuelResellersMessage($url));

        $io->success('Mensagem enviada para a fila. Execute o worker para processar:');
        $io->text('  php bin/console messenger:consume async --memory-limit=256M --time-limit=3600');

        return Command::SUCCESS;
    }
}
