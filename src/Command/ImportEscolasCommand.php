<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ImportEscolasMessage;
use App\MessageHandler\ImportEscolasHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa as escolas do Censo Escolar (INEP/MEC) — uma UF por vez ou todas.
 *
 * Uso:
 *   php bin/console app:import-escolas          # importa todos os 27 estados
 *   php bin/console app:import-escolas --uf=MG  # importa só Minas Gerais
 *   php bin/console app:import-escolas --uf=SP --uf=RJ  # importa SP e RJ
 */
#[AsCommand(
    name: 'app:import-escolas',
    description: 'Importa escolas do Censo Escolar (INEP/MEC) por estado com diff incremental',
)]
final class ImportEscolasCommand extends Command
{
    private const UFS = [
        'AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO',
        'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR',
        'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO',
    ];

    public function __construct(
        private readonly ImportEscolasHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'uf',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'UF(s) a importar (padrão: todas). Exemplo: --uf=MG --uf=SP',
            []
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ufs = array_map(
            'strtoupper',
            $input->getOption('uf') ?: self::UFS
        );

        $invalid = array_diff($ufs, self::UFS);

        if ($invalid !== []) {
            $io->error('UF(s) inválida(s): ' . implode(', ', $invalid));
            return Command::FAILURE;
        }

        $io->title('Importação de Escolas — Censo Escolar (INEP/MEC)');
        $io->text(sprintf('Estados: %s', implode(', ', $ufs)));
        $io->newLine();

        $start = microtime(true);
        $errors = [];

        foreach ($ufs as $uf) {
            $io->write(" → {$uf}... ");

            try {
                ($this->handler)(new ImportEscolasMessage($uf));
                $io->writeln('<info>OK</info>');
            } catch (\Throwable $e) {
                $io->writeln('<error>ERRO</error>');
                $errors[$uf] = $e->getMessage();
            }
        }

        $elapsed = round(microtime(true) - $start, 1);

        $io->newLine();

        if ($errors !== []) {
            $io->error('Falhas:');
            foreach ($errors as $uf => $msg) {
                $io->writeln("  {$uf}: {$msg}");
            }
        }

        $io->success(sprintf(
            'Concluído em %ss — %d/%d estado(s) importado(s).',
            $elapsed,
            count($ufs) - count($errors),
            count($ufs)
        ));

        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
