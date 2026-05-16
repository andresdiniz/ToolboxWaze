<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ImportRadarMedidoresMessage;
use App\MessageHandler\ImportRadarMedidoresHandler;
use App\Repository\BrazilianStateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Itera os 27 estados e processa cada um diretamente via handler.
 *
 * Por quê não usar o MessageBus?
 *   O transporte está configurado como sync:// — o bus executa o handler
 *   imediatamente e qualquer exceção num estado interrompe os demais.
 *   Chamando o handler diretamente com try/catch por estado, um erro
 *   em AC não impede AL, AM... e assim por diante.
 *
 * Uso:
 *   php bin/console app:import-radar-medidores           # todos os estados
 *   php bin/console app:import-radar-medidores --uf=SP   # só São Paulo
 *   php bin/console app:import-radar-medidores --uf=SP --uf=RJ
 */
#[AsCommand(
    name: 'app:import-radar-medidores',
    description: 'Importa medidores RBMLQ/INMETRO de todos os estados (ou UFs específicas)',
)]
final class ImportRadarMedidoresCommand extends Command
{
    public function __construct(
        private readonly ImportRadarMedidoresHandler  $handler,
        private readonly BrazilianStateRepository     $stateRepository,
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
        $io->title('Importação RBMLQ — Medidores por Estado');

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

        $total   = count($ufs);
        $ok      = 0;
        $errors  = [];

        $io->text(sprintf('Estados a processar: <info>%s</info>', implode(', ', $ufs)));
        $io->newLine();
        $io->progressStart($total);

        foreach ($ufs as $uf) {
            try {
                ($this->handler)(new ImportRadarMedidoresMessage($uf));
                $ok++;
            } catch (\Throwable $e) {
                $errors[$uf] = $e->getMessage();
            } finally {
                $io->progressAdvance();
            }
        }

        $io->progressFinish();

        if ($errors !== []) {
            $io->warning(sprintf('%d estado(s) com erro:', count($errors)));
            foreach ($errors as $uf => $msg) {
                $io->text(sprintf('  <comment>%s</comment>: %s', $uf, $msg));
            }
        }

        $io->success(sprintf(
            '%d/%d estado(s) importado(s) com sucesso. URL base: %s',
            $ok,
            $total,
            ImportRadarMedidoresMessage::BASE_URL
        ));

        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
