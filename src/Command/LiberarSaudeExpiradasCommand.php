<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Service\SaudeReservaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:saude:liberar-expiradas',
    description: 'Libera reservas de estabelecimentos de saúde sem renovação há mais tempo que o TTL.',
)]
final class LiberarSaudeExpiradasCommand extends Command
{
    public function __construct(
        private readonly SaudeReservaService $reservas,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ttl', 't', InputOption::VALUE_REQUIRED, 'Minutos sem renovação até a reserva expirar', (string) SaudeReservaService::TTL_MINUTOS)
            ->addOption('uf', null, InputOption::VALUE_REQUIRED, 'Limita a uma UF (ex.: PE)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Só conta quantas expirariam, sem alterar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $ttl = filter_var($input->getOption('ttl'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($ttl === false) {
            $io->error('--ttl deve ser um inteiro maior ou igual a 1 (minutos).');

            return Command::INVALID;
        }

        $uf = $input->getOption('uf');
        $uf = is_string($uf) && trim($uf) !== '' ? strtoupper(trim($uf)) : null;

        if ($uf !== null && !in_array($uf, User::ALL_UFS, true)) {
            $io->error(sprintf('UF inválida: %s', $uf));

            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $resultado = $this->reservas->liberarExpiradas($ttl, $uf, $dryRun);
        } catch (\Throwable $e) {
            $io->error('Falha ao liberar reservas: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s %d reserva(s) com mais de %d min%s.',
            $dryRun ? 'Expirariam' : 'Liberadas',
            $resultado['liberadas'],
            $ttl,
            $uf !== null ? " na UF {$uf}" : '',
        ));

        return Command::SUCCESS;
    }
}
