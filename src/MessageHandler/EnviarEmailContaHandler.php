<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnviarEmailConta;
use App\Repository\UserRepository;
use App\Service\EmailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Processa envios de e-mail relacionados a contas de usuário.
 */
#[AsMessageHandler]
final class EnviarEmailContaHandler
{
    public function __construct(
        private readonly EmailSender    $emailSender,
        private readonly UserRepository $userRepo,
        private readonly LoggerInterface $emailQueueLogger,
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

            $base     = $this->emailSender->baseUrl();
            $loginUrl = $base . '/login';
            $adminUrl = $base . '/admin/users';

            match ($message->tipo) {
                'conta_criada'           => $this->enviarContaCriada($user, $loginUrl, $context),
                'solicitacao_admin'      => $this->enviarSolicitacaoAdmin($message, $user, $adminUrl, $context),
                'aprovado'               => $this->enviarAprovado($user, $loginUrl, $context),
                'rejeitado'              => $this->enviarRejeitado($user, $context),
                'permissoes_atualizadas' => $this->enviarPermissoesAtualizadas($user, $loginUrl, $context),
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

    private function enviarContaCriada(object $user, string $loginUrl, array $context): void
    {
        $this->emailSender->sendTemplated(
            $user->getEmail(),
            $user->getName(),
            '[ToolboxWaze] Solicitação recebida — aguardando aprovação',
            'emails/conta_criada.html.twig',
            ['user' => $user, 'loginUrl' => $loginUrl]
        );

        $this->emailQueueLogger->info('Email [conta_criada] enviado', array_merge($context, [
            'destinatario' => $user->getEmail(),
        ]));
    }

    private function enviarSolicitacaoAdmin(EnviarEmailConta $message, object $user, string $adminUrl, array $context): void
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

        $this->emailSender->sendTemplated(
            $admin->getEmail(),
            $admin->getName(),
            '[ToolboxWaze] Nova solicitação de acesso: ' . $user->getName(),
            'emails/new_registration.html.twig',
            [
                'admin'         => $admin,
                'user'          => $user,
                'requestedUfs'  => $user->getAllowedUfs() ?? [],
                'adminUsersUrl' => $adminUrl,
            ]
        );

        $this->emailQueueLogger->info('Email [solicitacao_admin] enviado', array_merge($context, [
            'destinatario' => $admin->getEmail(),
            'solicitante'  => $user->getEmail(),
        ]));
    }

    private function enviarAprovado(object $user, string $loginUrl, array $context): void
    {
        $this->emailSender->sendTemplated(
            $user->getEmail(),
            $user->getName(),
            '[ToolboxWaze] Seu acesso foi aprovado!',
            'emails/user_approved.html.twig',
            [
                'user'       => $user,
                'allowedUfs' => $user->getAllowedUfs(),
                'loginUrl'   => $loginUrl,
            ]
        );

        $this->emailQueueLogger->info('Email [aprovado] enviado', array_merge($context, [
            'destinatario' => $user->getEmail(),
        ]));
    }

    private function enviarRejeitado(object $user, array $context): void
    {
        $this->emailSender->sendTemplated(
            $user->getEmail(),
            $user->getName(),
            '[ToolboxWaze] Atualização sobre sua solicitação de acesso',
            'emails/user_rejected.html.twig',
            ['user' => $user]
        );

        $this->emailQueueLogger->info('Email [rejeitado] enviado', array_merge($context, [
            'destinatario' => $user->getEmail(),
        ]));
    }

    private function enviarPermissoesAtualizadas(object $user, string $loginUrl, array $context): void
    {
        $this->emailSender->sendTemplated(
            $user->getEmail(),
            $user->getName(),
            '[ToolboxWaze] Suas permissões foram atualizadas',
            'emails/user_permissions_updated.html.twig',
            [
                'user'       => $user,
                'allowedUfs' => $user->getAllowedUfs(),
                'loginUrl'   => $loginUrl,
            ]
        );

        $this->emailQueueLogger->info('Email [permissoes_atualizadas] enviado', array_merge($context, [
            'destinatario' => $user->getEmail(),
        ]));
    }
}
