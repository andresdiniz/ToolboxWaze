<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * #14 – Purga automática da tabela monitoring_event.
 *
 * Uso:
 *   php bin/console app:monitoring:purge
 *   php bin/console app:monitoring:purge --days=60   # muda retenção
 *   php bin/console app:monitoring:purge --dry-run   # só mostra quantos seriam deletados
 *
 * Cron sugerido (diariamente às 03h):
 *   0 3 * * * /usr/bin/php /var/www/html/bin/console app:monitoring:purge --env=prod
 */
#[AsCommand(
    name: 'app:monitoring:purge',
    description: 'Remove eventos de monitoring_event mais antigos que N dias (padrão: 30).',
)]
final class MonitoringPurgeCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days',    'd', InputOption::VALUE_OPTIONAL, 'Retenção em dias', 30)
            ->addOption('dry-run', null, InputOption::VALUE_NONE,    'Apenas conta, não deleta');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $days    = (int) $input->getOption('days');
        $dryRun  = (bool) $input->getOption('dry-run');
        $cutoff  = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

        if ($days < 1) {
            $io->error('--days deve ser >= 1.');
            return Command::FAILURE;
        }

        $count = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM monitoring_event WHERE created_at < ?',
            [$cutoff]
        );

        if ($dryRun) {
            $io->info("[dry-run] {$count} evento(s) seriam deletados (criados antes de {$cutoff}).");
            return Command::SUCCESS;
        }

        if ($count === 0) {
            $io->success('Nada a purgar.');
            return Command::SUCCESS;
        }

        // Deleta em lotes para não travar a tabela
        $deleted = 0;
        do {
            $batch    = $this->db->executeStatement(
                'DELETE FROM monitoring_event WHERE created_at < ? LIMIT 1000',
                [$cutoff]
            );
            $deleted += $batch;
        } while ($batch > 0);

        $io->success("Purgados {$deleted} evento(s) anteriores a {$cutoff} (retenção: {$days} dias).");

        return Command::SUCCESS;
    }
}
