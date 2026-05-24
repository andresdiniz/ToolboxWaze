<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use app:import-radar (ImportRadarCommand) no lugar.
 *
 * Mantido apenas como alias para não quebrar scripts existentes.
 * Redireciona internamente para o novo command unificado.
 */
#[AsCommand(
    name: 'app:import-radar-medidores-legacy',
    description: '[DEPRECATED] Use app:import-radar. Alias mantido para compatibilidade.',
    hidden: true,
)]
final class ImportRadarMedidoresCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('uf', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []);
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, '', 'sheets');
        $this->addOption('skip-waze', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>[DEPRECATED] Use php bin/console app:import-radar</comment>');
        return Command::SUCCESS;
    }
}
