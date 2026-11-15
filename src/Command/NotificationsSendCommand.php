<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Notification\EmailNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:notificacoes:enviar', description: 'Envia notificações por e-mail pendentes.')]
final class NotificationsSendCommand extends Command
{
    public function __construct(private readonly EmailNotificationService $notifications)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Quantidade máxima de entregas por execução', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->notifications->sendPending(min(500, max(1, (int) $input->getOption('limite'))));

        $output->writeln(sprintf('Enviados: %d | Falhas: %d | Ignorados: %d', $result['sent'], $result['failed'], $result['skipped']));

        return Command::SUCCESS;
    }
}
