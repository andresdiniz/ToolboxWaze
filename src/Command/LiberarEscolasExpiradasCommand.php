<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Service\EscolaReservaService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:escolas:liberar-expiradas',
    description: 'Libera reservas de escolas sem renovação há mais tempo que o TTL e registra o tempo de execução.',
)]
final class LiberarEscolasExpiradasCommand extends Command
{
    public function __construct(
        private readonly EscolaReservaService $reservas,
        #[Autowire(service: 'monolog.logger.escolas')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ttl', 't', InputOption::VALUE_REQUIRED, 'Minutos sem renovação até a reserva expirar', (string) EscolaReservaService::TTL_MINUTOS)
            ->addOption('uf', null, InputOption::VALUE_REQUIRED, 'Limita a uma UF (ex.: MG)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Só conta quantas expirariam, sem alterar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $inicio = hrtime(true);
        $iniciadoEm = new \DateTimeImmutable();

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

        // Comando exatamente como foi chamado (ex.: "bin/console app:escolas:liberar-expiradas --ttl=30").
        $comando = implode(' ', array_map('strval', $_SERVER['argv'] ?? []));

        $contexto = [
            'evento' => 'comando_expiracao',
            'comando' => $comando,
            'entrada' => (string) $input,
            'ttl_minutos' => $ttl,
            'uf' => $uf,
            'dry_run' => $dryRun,
            'iniciado_em' => $iniciadoEm->format(DATE_ATOM),
        ];

        try {
            $resultado = $this->reservas->liberarExpiradas($ttl, $uf, $dryRun);
        } catch (\Throwable $e) {
            $this->logger->error('comando_expiracao_falhou', $contexto + [
                'duracao_ms' => round((hrtime(true) - $inicio) / 1_000_000, 2),
                'erro' => $e->getMessage(),
                'classe' => $e::class,
            ]);

            $io->error('Falha ao liberar reservas: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $duracaoMs = round((hrtime(true) - $inicio) / 1_000_000, 2);

        $this->logger->info('comando_expiracao', $contexto + [
            'finalizado_em' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'duracao_ms' => $duracaoMs,
            'liberadas' => $resultado['liberadas'],
            'corte' => $resultado['corte']->format(DATE_ATOM),
            'consulta' => $resultado['dql'],
            'memoria_pico_mb' => round(memory_get_peak_usage(true) / 1_048_576, 2),
        ]);

        $io->success(sprintf(
            '%s %d reserva(s) com mais de %d min%s em %s ms.',
            $dryRun ? 'Expirariam' : 'Liberadas',
            $resultado['liberadas'],
            $ttl,
            $uf !== null ? " na UF {$uf}" : '',
            $duracaoMs,
        ));

        return Command::SUCCESS;
    }
}
