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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Processa envios de e-mail relacionados a contas de usuário.
 *
 * Tipos tratados:
 *   - 'conta_criada'      → confirmação ao próprio usuário após criar a conta
 *   - 'solicitacao_admin' → aviso ao admin de nova solicitação de acesso
 */
#[AsMessageHandler]
final class EnviarEmailContaHandler
{
    public function __construct(
        private readonly MailerInterface         $mailer,
        private readonly UserRepository          $userRepo,
        private readonly LoggerInterface         $emailQueueLogger,
        private readonly UrlGeneratorInterface   $urlGenerator,
        private readonly string                  $mailerFrom  = '',
        private readonly string                  $appBaseUrl  = '', // mantido para compatibilidade com services.yaml do servidor
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

            $from = $this->parseFrom();

            match ($message->tipo) {
                'conta_criada'      => $this->enviarContaCriada($user, $from, $context),
                'solicitacao_admin' => $this->enviarSolicitacaoAdmin($message, $user, $from, $context),
                default             => $this->emailQueueLogger->warning('EnviarEmailConta: tipo desconhecido', $context),
            };
        } catch (\Throwable $e) {
            $this->emailQueueLogger->error('EnviarEmailConta: falha ao processar', array_merge($context, [
                'exception' => $e->getMessage(),
            ]));
            throw $e;
        }
    }

    // ---------------------------------------------------------------

    private function enviarContaCriada(object $user, Address $from, array $context): void
    {
        $email = (new TemplatedEmail())
            ->from($from)
            ->to(new Address($user->getEmail(), $user->getName()))
            ->subject('[ToolboxWaze] Solicitação recebida — aguardando aprovação')
            ->htmlTemplate('emails/conta_criada.html.twig')
            ->context(['user' => $user]);

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

        $adminUsersUrl = $this->urlGenerator->generate(
            'admin_users_index',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $email = (new TemplatedEmail())
            ->from($from)
            ->to(new Address($admin->getEmail(), $admin->getName()))
            ->subject('[ToolboxWaze] Nova solicitação de acesso: ' . $user->getName())
            ->htmlTemplate('emails/new_registration.html.twig')
            ->context([
                'admin'         => $admin,
                'user'          => $user,
                'requestedUfs'  => $user->getAllowedUfs() ?? [],
                'adminUsersUrl' => $adminUsersUrl,
            ]);

        $this->mailer->send($email);

        $this->emailQueueLogger->info('Email conta[solicitacao_admin] enviado', array_merge($context, [
            'destinatario' => $admin->getEmail(),
            'solicitante'  => $user->getEmail(),
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
