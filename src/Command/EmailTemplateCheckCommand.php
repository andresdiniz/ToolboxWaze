<?php

declare(strict_types=1);

namespace App\Command;

use Twig\Environment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:email:check-template', description: 'Renderiza um template de e-mail sem enviar mensagem.')]
final class EmailTemplateCheckCommand extends Command
{
    public function __construct(private readonly Environment $twig)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('template', InputArgument::REQUIRED, 'Nome do template Twig, por exemplo email/welcome_user.html.twig.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $template = trim((string) $input->getArgument('template'));
        if ($template === '' || !str_starts_with($template, 'email/')) {
            $output->writeln('<error>Informe um template dentro de templates/email.</error>');
            return Command::INVALID;
        }

        try {
            $html = $this->twig->render($template, [
                'userName' => 'Usuário de teste',
                'userEmail' => 'teste@example.com',
                'states' => ['MG', 'SP'],
                'radars' => [],
                'radarsByState' => [],
                'resetUrl' => 'https://example.com/reset/test-token',
                'loginUrl' => 'https://example.com/login',
                'adminUrl' => 'https://example.com/admin/users/1',
                'expiresIn' => '1 hora',
            ]);
        } catch (\Throwable $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Template válido: %s (%d bytes).</info>', $template, strlen($html)));
        return Command::SUCCESS;
    }
}
