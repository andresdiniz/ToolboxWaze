<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnviarEmailConta;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Processa envios de e-mail relacionados a contas de usuário.
 *
 * Tipos tratados:
 *   - 'conta_criada'           → confirmação ao usuário após criar conta
 *   - 'solicitacao_admin'      → aviso ao admin de nova solicitação
 *   - 'aprovado'               → conta aprovada pelo admin
 *   - 'rejeitado'              → conta rejeitada pelo admin
 *   - 'permissoes_atualizadas' → permissões do usuário alteradas pelo admin
 */
#[AsMessageHandler]
final class EnviarEmailContaHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository  $userRepo,
        private readonly LoggerInterface $emailQueueLogger,
        private readonly string          $mailerFrom  = '',
        private readonly string          $appBaseUrl  = '',
    ) {}

    public function __invoke(EnviarEmailConta $message): void
    {
        $context = [
            'tipo'     => $message->tipo,
            'user_id'  => $message->userId,
            'admin_id' => $message->adminId,
        ];

        try {
            $user = $this->userRepo->find($message->userId);
            if (!$user) {
                $this->emailQueueLogger->warning('EnviarEmailConta: usuário não encontrado', $context);
                return;
            }

            $from       = $this->parseFrom();
            $base       = rtrim($this->appBaseUrl, '/');
            $loginUrl   = $base . '/login';
            $adminUrl   = $base . '/admin/users';

            match ($message->tipo) {
                'conta_criada'           => $this->enviarContaCriada($user, $from, $loginUrl, $context),
                'solicitacao_admin'      => $this->enviarSolicitacaoAdmin($message, $user, $from, $adminUrl, $context),
                'aprovado'               => $this->enviarAprovado($user, $from, $loginUrl, $context),
                'rejeitado'              => $this->enviarRejeitado($user, $from, $context),
                'permissoes_atualizadas' => $this->enviarPermissoesAtualizadas($user, $from, $loginUrl, $context),
                default                  => $this->emailQueueLogger->warning('EnviarEmailConta: tipo desconhecido', $context),
            };
        } catch (\Throwable $e) {
            $this->emailQueueLogger->error('EnviarEmailConta: falha ao processar', array_merge($context, [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]));
            throw $e;
        }
    }

    // ---------------------------------------------------------------

    private function enviarContaCriada(object $user, Address $from, string $loginUrl, array $context): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($from)
                ->to(new Address($user->getEmail(), $user->getName()))
                ->subject('[ToolboxWaze] Solicitação recebida — aguardando aprovação')
                ->htmlTemplate('emails/conta_criada.html.twig')
                ->context(['user' => $user, 'loginUrl' => $loginUrl])
        );

        $this->emailQueueLogger->info('Email [conta_criada] enviado', array_merge($context, [
            'destinatario' => $user->getEmail(),
        ]));
    }

    private function enviarSolicitacaoAdmin(EnviarEmailConta $message, object $user, Address $from, string $adminUrl, array $context): void
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

        $this->mailer->send(
            (new TemplatedEmail())
                ->from($from)
                ->to(new Address($admin->getEmail(), $admin->getName()))
                ->subject('[ToolboxWaze] Nova solicitação de acesso: ' . $user->getName())
                ->htmlTemplate('emails/new_registration.html.twig')
                ->context([
                    'admin'         => $admin,
                    'user'          => $user,
                    'requestedUfs'  => $user->getAllowedUfs() ?? [],
                    'adminUsersUrl' => $adminUrl,
                ])
        );

        $this->emailQueueLogger->info('Email [solicitacao_admin] enviado', array_merge($context, [
            'destinatario' => $admin->getEmail(),
            'solicitante'  => $user->getEmail(),
        ]));
    }

    private function enviarAprovado(object $user, Address $from, string $loginUrl, array $context): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($from)
                ->to(new Address($user->getEmail(), $user->getName()))
                ->subject('[ToolboxWaze] Seu acesso foi aprovado!')
                ->htmlTemplate('emails/user_approved.html.twig')
                ->context([
                    'user'       => $user,
                    'allowedUfs' => $user->getAllowedUfs(),
                    'loginUrl'   => $loginUrl,
                ])
        );

        $this->emailQueueLogger->info('Email [aprovado] enviado', array_merge($context, [
            'destinatario' => $user->getEmail(),
        ]));
    }

    private function enviarRejeitado(object $user, Address $from, array $context): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($from)
                ->to(new Address($user->getEmail(), $user->getName()))
                ->subject('[ToolboxWaze] Atualização sobre sua solicitação de acesso')
                ->htmlTemplate('emails/user_rejected.html.twig')
                ->context(['user' => $user])
        );

        $this->emailQueueLogger->info('Email [rejeitado] enviado', array_merge($context, [
            'destinatario' => $user->getEmail(),
        ]));
    }

    private function enviarPermissoesAtualizadas(object $user, Address $from, string $loginUrl, array $context): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($from)
                ->to(new Address($user->getEmail(), $user->getName()))
                ->subject('[ToolboxWaze] Suas permissões foram atualizadas')
                ->htmlTemplate('emails/user_permissions_updated.html.twig')
                ->context([
                    'user'       => $user,
                    'allowedUfs' => $user->getAllowedUfs(),
                    'loginUrl'   => $loginUrl,
                ])
        );

        $this->emailQueueLogger->info('Email [permissoes_atualizadas] enviado', array_merge($context, [
            'destinatario' => $user->getEmail(),
        ]));
    }

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
