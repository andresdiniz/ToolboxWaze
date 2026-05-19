<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnviarEmailConta;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Processa envios de e-mail relacionados a contas de usuário.
 *
 * Tipos tratados:
 *   - 'criacao'           → boas-vindas + link de primeiro acesso
 *   - 'conta_criada'      → confirmação de ativação
 *   - 'solicitacao_admin' → aviso ao admin de nova solicitação de acesso
 *
 * Todos os eventos são registrados no canal 'email_queue' (var/log/email_queue.log).
 */
#[AsMessageHandler]
final class EnviarEmailContaHandler
{
    public function __construct(
        private readonly MailerInterface  $mailer,
        private readonly UserRepository   $userRepo,
        private readonly LoggerInterface  $emailQueueLogger, // injetado pelo canal 'email_queue'
        private readonly string           $mailerFrom = '',
        private readonly string           $appBaseUrl  = '',
    ) {}

    public function __invoke(EnviarEmailConta $message): void
    {
        $context = [
            'tipo'    => $message->tipo,
            'user_id' => $message->userId,
            'admin_id'=> $message->adminId,
        ];

        try {
            $user = $this->userRepo->find($message->userId);
            if (!$user) {
                $this->emailQueueLogger->warning('EnviarEmailConta: usuário não encontrado', $context);
                return;
            }

            $from = $this->parseFrom();

            switch ($message->tipo) {
                case 'criacao':
                    $this->enviarCriacao($user, $from, $context);
                    break;

                case 'conta_criada':
                    $this->enviarContaCriada($user, $from, $context);
                    break;

                case 'solicitacao_admin':
                    $this->enviarSolicitacaoAdmin($message, $user, $from, $context);
                    break;

                default:
                    $this->emailQueueLogger->warning('EnviarEmailConta: tipo desconhecido', $context);
                    return;
            }
        } catch (\Throwable $e) {
            $this->emailQueueLogger->error('EnviarEmailConta: falha ao processar', array_merge($context, [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]));
            throw $e; // repropaga para o Messenger registrar como failed
        }
    }

    // ---------------------------------------------------------------

    private function enviarCriacao(object $user, Address $from, array $context): void
    {
        $email = (new Email())
            ->from($from)
            ->to(new Address($user->getEmail(), $user->getNome() ?? $user->getEmail()))
            ->subject('Bem-vindo ao ToolboxWaze — seus dados de acesso')
            ->html($this->templateCriacao($user));

        $this->mailer->send($email);

        $this->emailQueueLogger->info('Email conta[criacao] enviado', array_merge($context, [
            'destinatario' => $user->getEmail(),
        ]));
    }

    private function enviarContaCriada(object $user, Address $from, array $context): void
    {
        $email = (new Email())
            ->from($from)
            ->to(new Address($user->getEmail(), $user->getNome() ?? $user->getEmail()))
            ->subject('Sua conta no ToolboxWaze foi ativada')
            ->html($this->templateContaCriada($user));

        $this->mailer->send($email);

        $this->emailQueueLogger->info('Email conta[conta_criada] enviado', array_merge($context, [
            'destinatario' => $user->getEmail(),
        ]));
    }

    private function enviarSolicitacaoAdmin(EnviarEmailConta $message, object $user, Address $from, array $context): void
    {
        if (!$message->adminId) {
            $this->emailQueueLogger->warning('EnviarEmailConta[solicitacao_admin]: adminId ausente', $context);
            return;
        }

        $admin = $this->userRepo->find($message->adminId);
        if (!$admin) {
            $this->emailQueueLogger->warning('EnviarEmailConta[solicitacao_admin]: admin não encontrado', $context);
            return;
        }

        $email = (new Email())
            ->from($from)
            ->to(new Address($admin->getEmail(), $admin->getNome() ?? $admin->getEmail()))
            ->subject('[ToolboxWaze] Nova solicitação de acesso: ' . ($user->getNome() ?? $user->getEmail()))
            ->html($this->templateSolicitacaoAdmin($user, $admin));

        $this->mailer->send($email);

        $this->emailQueueLogger->info('Email conta[solicitacao_admin] enviado', array_merge($context, [
            'destinatario' => $admin->getEmail(),
            'solicitante'  => $user->getEmail(),
        ]));
    }

    // ---------------------------------------------------------------
    // Templates HTML simples (substitua por Twig se preferir)
    // ---------------------------------------------------------------

    private function templateCriacao(object $user): string
    {
        $nome    = htmlspecialchars($user->getNome() ?? $user->getEmail(), ENT_QUOTES);
        $baseUrl = rtrim($this->appBaseUrl, '/');
        return <<<HTML
        <p>Olá, <strong>{$nome}</strong>!</p>
        <p>Sua conta no <strong>ToolboxWaze</strong> foi criada.</p>
        <p>Acesse: <a href="{$baseUrl}/login">{$baseUrl}/login</a></p>
        <p>Se você não solicitou este cadastro, ignore este e-mail.</p>
        HTML;
    }

    private function templateContaCriada(object $user): string
    {
        $nome = htmlspecialchars($user->getNome() ?? $user->getEmail(), ENT_QUOTES);
        return <<<HTML
        <p>Olá, <strong>{$nome}</strong>!</p>
        <p>Sua conta no <strong>ToolboxWaze</strong> foi ativada com sucesso. Você já pode acessar o sistema.</p>
        HTML;
    }

    private function templateSolicitacaoAdmin(object $user, object $admin): string
    {
        $nomeUser  = htmlspecialchars($user->getNome()  ?? $user->getEmail(),  ENT_QUOTES);
        $nomeAdmin = htmlspecialchars($admin->getNome() ?? $admin->getEmail(), ENT_QUOTES);
        $email     = htmlspecialchars($user->getEmail(), ENT_QUOTES);
        $baseUrl   = rtrim($this->appBaseUrl, '/');
        return <<<HTML
        <p>Olá, <strong>{$nomeAdmin}</strong>!</p>
        <p>O usuário <strong>{$nomeUser}</strong> ({$email}) solicitou acesso ao ToolboxWaze.</p>
        <p>Acesse o painel administrativo para aprovar ou recusar:</p>
        <p><a href="{$baseUrl}/admin">Painel Admin</a></p>
        HTML;
    }

    // ---------------------------------------------------------------
    // Helper: parse "Nome <email>" ou só "email"
    // ---------------------------------------------------------------

    private function parseFrom(): Address
    {
        $raw = trim($this->mailerFrom);
        if (preg_match('/^(.+?)\s*<([^>]+)>\s*$/', $raw, $m)) {
            return new Address(trim($m[2]), trim($m[1]));
        }
        return new Address($raw ?: 'noreply@toolboxwaze.com.br');
    }
}
