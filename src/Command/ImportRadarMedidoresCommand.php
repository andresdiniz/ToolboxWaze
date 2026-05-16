<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ImportRadarMedidoresMessage;
use App\Repository\BrazilianStateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Itera os 27 estados da tabela brazilian_state e despacha
 * uma ImportRadarMedidoresMessage por estado.
 *
 * Uso:
 *   php bin/console app:import-radar-medidores           # todos os estados
 *   php bin/console app:import-radar-medidores --uf=SP   # só São Paulo
 *   php bin/console app:import-radar-medidores --uf=SP --uf=RJ  # SP e RJ
 */
#[AsCommand(
    name: 'app:import-radar-medidores',
    description: 'Importa medidores RBMLQ/INMETRO de todos os estados (ou UFs específicas)',
)]
final class ImportRadarMedidoresCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface    $bus,
        private readonly BrazilianStateRepository $stateRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
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

        $io->title('Importação RBMLQ — Medidores por Estado');

        // Decide quais UFs processar
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

        $io->text(sprintf('Estados a processar: <info>%s</info>', implode(', ', $ufs)));
        $io->newLine();

        $io->progressStart(count($ufs));

        foreach ($ufs as $uf) {
            $io->text(sprintf('  → Despachando mensagem para <comment>%s</comment>...', $uf));
            $this->bus->dispatch(new ImportRadarMedidoresMessage($uf));
            $io->progressAdvance();
        }

        $io->progressFinish();

        $io->success(sprintf(
            '%d estado(s) processado(s). URL base: %s',
            count($ufs),
            ImportRadarMedidoresMessage::BASE_URL
        ));

        return Command::SUCCESS;
    }
}
