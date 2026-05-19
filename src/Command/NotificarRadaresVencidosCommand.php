<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\EnviarEmailRadarVencido;
use App\Repository\RadarMedidorRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Verifica radares com data de fim vencida há mais de 30 dias e dispara
 * e-mail de aviso para todos os editores (ROLE_EDITOR) via Messenger.
 *
 * Ideal para rodar como cron diário:
 *   0 8 * * * php /caminho/bin/console app:notificar-radares-vencidos
 *
 * USO MANUAL:
 *   # Simulação (dry-run) — lista radares sem disparar e-mails
 *   php bin/console app:notificar-radares-vencidos --dry-run
 *
 *   # Execução real
 *   php bin/console app:notificar-radares-vencidos
 *
 *   # Alterar o limite de dias (padrão: 30)
 *   php bin/console app:notificar-radares-vencidos --dias=45
 */
#[AsCommand(
    name: 'app:notificar-radares-vencidos',
    description: 'Notifica editores sobre radares com data de fim vencida há mais de N dias',
)]
final class NotificarRadaresVencidosCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface    $bus,
        private readonly RadarMedidorRepository $radarRepo,
        private readonly UserRepository         $userRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dias',    null, InputOption::VALUE_REQUIRED, 'Dias mínimos após vencimento', 30)
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Lista sem disparar e-mails')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dias   = (int) $input->getOption('dias');
        $dryRun = (bool) $input->getOption('dry-run');

        $corte  = new \DateTimeImmutable("-{$dias} days");

        // Busca radares com data_fim anterior ao corte
        $radares = $this->radarRepo->findVencidosAntesDe($corte);

        if (empty($radares)) {
            $io->success("Nenhum radar vencido há mais de {$dias} dias encontrado.");
            return Command::SUCCESS;
        }

        // Busca todos os editores
        $editores = $this->userRepo->findByRole('ROLE_EDITOR');

        if (empty($editores)) {
            $io->warning('Nenhum editor (ROLE_EDITOR) encontrado. Nenhum e-mail enviado.');
            return Command::SUCCESS;
        }

        $io->section(sprintf(
            '%d radar(es) vencido(s) • %d editor(es) • dry-run: %s',
            count($radares),
            count($editores),
            $dryRun ? 'SIM' : 'NÃO'
        ));

        $rows = [];
        $total = 0;

        foreach ($radares as $radar) {
            $codigo  = method_exists($radar, 'getCodigo') ? $radar->getCodigo() : '#' . $radar->getId();
            $dataFim = (method_exists($radar, 'getDataFim') && $radar->getDataFim())
                ? $radar->getDataFim()->format('d/m/Y')
                : '-';

            foreach ($editores as $editor) {
                if (!$dryRun) {
                    try {
                        $this->bus->dispatch(new EnviarEmailRadarVencido($radar->getId(), $editor->getId()));
                        $status = '✅ despachado';
                    } catch (\Throwable $e) {
                        $status = '❌ ' . $e->getMessage();
                    }
                } else {
                    $status = '🔍 dry-run';
                }

                $rows[] = [$codigo, $dataFim, $editor->getEmail(), $status];
                ++$total;
            }
        }

        $io->table(['Radar', 'Data fim', 'Editor', 'Status'], $rows);

        if ($dryRun) {
            $io->note("Dry-run: {$total} e-mail(s) seriam despachados. Rode sem --dry-run para disparar.");
        } else {
            $io->success("{$total} mensagem(ns) despachada(s) via Messenger.");
            $io->note('Execute: php bin/console messenger:consume async -vv');
        }

        return Command::SUCCESS;
    }
}
