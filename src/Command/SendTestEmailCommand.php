<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envia um e-mail de teste para verificar a configuração do Mailer.
 *
 * Uso:
 *   php bin/console app:send-test-email voce@exemplo.com
 *   php bin/console app:send-test-email voce@exemplo.com --from=noreply@seusite.com
 *   php bin/console app:send-test-email voce@exemplo.com --subject="Meu teste"
 */
#[AsCommand(
    name: 'app:send-test-email',
    description: 'Envia um e-mail de teste para verificar a configuração do Mailer (MAILER_DSN)',
)]
final class SendTestEmailCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'to',
                InputArgument::REQUIRED,
                'Endereço de e-mail destinatário'
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Remetente (padrão: lê MAILER_FROM do .env ou usa noreply@localhost)',
                null
            )
            ->addOption(
                'subject',
                null,
                InputOption::VALUE_REQUIRED,
                'Assunto do e-mail de teste',
                'ToolboxWaze — Teste de E-mail'
            )
            ->setHelp(<<<'HELP'
Envia um e-mail de teste simples usando o transporte configurado em MAILER_DSN.

Exemplos:

  # Envia para seu e-mail pessoal
  php bin/console app:send-test-email voce@gmail.com

  # Especifica remetente e assunto
  php bin/console app:send-test-email voce@gmail.com --from=noreply@meusite.com --subject="Teste OK"

Se o envio falhar, verifique:
  - MAILER_DSN no .env.local
  - Credenciais SMTP (host, porta, usuário, senha)
  - Firewall/porta 465 ou 587 liberada no servidor
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $to      = $input->getArgument('to');
        $subject = $input->getOption('subject');
        $from    = $input->getOption('from')
            ?? $_ENV['MAILER_FROM']
            ?? ('noreply@' . gethostname());

        $io->title('ToolboxWaze — Envio de E-mail de Teste');
        $io->definitionList(
            ['De'      => $from],
            ['Para'    => $to],
            ['Assunto' => $subject],
            ['DSN'     => $this->maskDsn($_ENV['MAILER_DSN'] ?? '(não definido)')],
        );

        $sentAt = new \DateTimeImmutable();

        $email = (new Email())
            ->from(new Address($from, 'ToolboxWaze'))
            ->to($to)
            ->subject($subject)
            ->text($this->buildTextBody($from, $to, $sentAt))
            ->html($this->buildHtmlBody($from, $to, $subject, $sentAt));

        try {
            $io->text('Enviando...');
            $this->mailer->send($email);

            $io->success(sprintf(
                'E-mail enviado com sucesso para %s às %s',
                $to,
                $sentAt->format('d/m/Y H:i:s')
            ));

            return Command::SUCCESS;
        } catch (TransportExceptionInterface $e) {
            $io->error([
                'Falha ao enviar o e-mail:',
                $e->getMessage(),
                '',
                'Verifique o MAILER_DSN no .env.local',
            ]);

            return Command::FAILURE;
        }
    }

    // -------------------------------------------------------------------------
    // Corpo do e-mail
    // -------------------------------------------------------------------------

    private function buildTextBody(string $from, string $to, \DateTimeImmutable $sentAt): string
    {
        return sprintf(
            "Este é um e-mail de teste enviado pelo ToolboxWaze.\n\n" .
            "De:       %s\n" .
            "Para:     %s\n" .
            "Enviado:  %s\n\n" .
            "Se você recebeu esta mensagem, a configuração de e-mail está funcionando corretamente.\n",
            $from,
            $to,
            $sentAt->format('d/m/Y H:i:s')
        );
    }

    private function buildHtmlBody(
        string $from,
        string $to,
        string $subject,
        \DateTimeImmutable $sentAt,
    ): string {
        $fromHtml    = htmlspecialchars($from,    ENT_QUOTES, 'UTF-8');
        $toHtml      = htmlspecialchars($to,      ENT_QUOTES, 'UTF-8');
        $subjectHtml = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $dateHtml    = $sentAt->format('d/m/Y H:i:s');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <title>{$subjectHtml}</title>
        </head>
        <body style="font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 560px; margin: 0 auto;">
                <tr>
                    <td style="background: #01696f; padding: 24px 32px; border-radius: 8px 8px 0 0;">
                        <h1 style="color: #ffffff; margin: 0; font-size: 20px;">ToolboxWaze</h1>
                        <p style="color: #a0d4d7; margin: 4px 0 0; font-size: 13px;">E-mail de Teste</p>
                    </td>
                </tr>
                <tr>
                    <td style="background: #ffffff; padding: 32px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 8px rgba(0,0,0,.08);">
                        <p style="color: #28251d; font-size: 15px; margin: 0 0 24px;">
                            Este é um e-mail de teste enviado automaticamente pelo sistema.
                            Se você recebeu esta mensagem, a configuração de e-mail está <strong>funcionando corretamente</strong>.
                        </p>
                        <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse: collapse; font-size: 14px; color: #555;">
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="width: 90px; color: #999;">De</td>
                                <td><strong>{$fromHtml}</strong></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="color: #999;">Para</td>
                                <td><strong>{$toHtml}</strong></td>
                            </tr>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="color: #999;">Assunto</td>
                                <td>{$subjectHtml}</td>
                            </tr>
                            <tr>
                                <td style="color: #999;">Enviado</td>
                                <td>{$dateHtml}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 16px 0; text-align: center; color: #bbb; font-size: 12px;">
                        ToolboxWaze &mdash; gerado automaticamente
                    </td>
                </tr>
            </table>
        </body>
        </html>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Oculta a senha no DSN para exibição segura no terminal.
     * smtp://user:SENHA@host:port -> smtp://user:***@host:port
     */
    private function maskDsn(string $dsn): string
    {
        // Usa # como delimitador para evitar conflito com as barras do DSN
        return preg_replace('#(://[^:]+:)([^@]+)(@)#', '$1***$3', $dsn) ?? $dsn;
    }
}
