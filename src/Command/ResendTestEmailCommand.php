<?php

declare(strict_types=1);

namespace App\Command;

use App\Email\Message\SendEmailMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:resend:test-email', description: 'Despacha um e-mail de teste para o Resend.')]
final class ResendTestEmailCommand extends Command
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::REQUIRED, 'Endereço de destino.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = trim((string) $input->getArgument('to'));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>Endereço de destino inválido.</error>');
            return Command::INVALID;
        }

        $this->bus->dispatch(new SendEmailMessage(
            to: $to,
            subject: 'Teste de envio — Toolbox Waze',
            template: 'email/welcome_user.html.twig',
            context: [
                'userName' => 'Teste',
                'loginUrl' => null,
            ],
            type: 'system_test',
        ));

        $output->writeln(sprintf('<info>Mensagem despachada para %s.</info>', $to));
        return Command::SUCCESS;
    }
}
