<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(name: 'app:resend:check-config', description: 'Valida a configuração do Resend sem enviar e-mail.')]
final class ResendConfigCheckCommand extends Command
{
    public function __construct(private readonly ParameterBagInterface $parameters)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apiKey = trim((string) $this->parameters->get('app.resend_api_key'));
        $from = trim((string) $this->parameters->get('app.mailer_from'));
        $fromName = trim((string) $this->parameters->get('app.mailer_from_name'));

        $valid = true;
        if ($apiKey === '') {
            $output->writeln('<error>RESEND_API_KEY não configurada.</error>');
            $valid = false;
        } else {
            $output->writeln('<info>RESEND_API_KEY configurada.</info>');
        }

        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>MAILER_FROM_EMAIL inválido ou vazio.</error>');
            $valid = false;
        } else {
            $output->writeln(sprintf('<info>Remetente válido: %s</info>', $from));
        }

        if ($fromName === '') {
            $output->writeln('<comment>MAILER_FROM_NAME vazio; será usado somente o endereço.</comment>');
        } else {
            $output->writeln(sprintf('<info>Nome do remetente: %s</info>', $fromName));
        }

        return $valid ? Command::SUCCESS : Command::FAILURE;
    }
}
