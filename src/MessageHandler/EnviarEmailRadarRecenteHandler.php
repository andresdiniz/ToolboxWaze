<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnviarEmailRadarRecente;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Processa a fila de notificações de radares recentes.
 *
 * Executado de forma assíncrona pelo Messenger consumer, portanto
 * o ImportRadarCommand retorna imediatamente após o dispatch.
 */
#[AsMessageHandler]
final class EnviarEmailRadarRecenteHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository  $userRepo,
        private readonly LoggerInterface $emailQueueLogger,
        #[Autowire(env: 'MAILER_FROM')] private readonly string $mailerFrom = '',
        #[Autowire(env: 'APP_BASE_URL')] private readonly string $appBaseUrl  = '',
    ) {}

    public function __invoke(EnviarEmailRadarRecente $message): void
    {
        $context = [
            'sigla_uf'         => $message->siglaUf,
            'user_id'          => $message->userId,
            'quantidade_novos' => $message->quantidadeNovos,
        ];

        try {
            $user = $this->userRepo->find($message->userId);

            if (!$user) {
                $this->emailQueueLogger->warning(
                    'EnviarEmailRadarRecente: usuário não encontrado',
                    $context
                );
                return;
            }

            // Revalida acesso à UF no momento do envio (pode ter mudado desde o dispatch)
            if (!$user->canAccessUf($message->siglaUf)) {
                $this->emailQueueLogger->warning(
                    'EnviarEmailRadarRecente: usuário sem acesso à UF — e-mail cancelado',
                    $context
                );
                return;
            }

            $email = (new Email())
                ->from($this->parseFrom())
                ->to(new Address($user->getEmail(), $user->getName()))
                ->subject(sprintf(
                    '[ToolboxWaze] %d novo(s) radar(es) adicionado(s) em %s',
                    $message->quantidadeNovos,
                    $message->siglaUf
                ))
                ->html($this->buildHtml(
                    $user->getName(),
                    $message->siglaUf,
                    $message->quantidadeNovos
                ));

            $this->mailer->send($email);

            $this->emailQueueLogger->info('EnviarEmailRadarRecente: e-mail enviado', array_merge($context, [
                'destinatario' => $user->getEmail(),
            ]));

        } catch (\Throwable $e) {
            $this->emailQueueLogger->error('EnviarEmailRadarRecente: falha ao enviar', array_merge($context, [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]));
            throw $e;
        }
    }

    private function buildHtml(string $nome, string $uf, int $quantidade): string
    {
        $nome    = htmlspecialchars($nome,    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ufSafe  = htmlspecialchars($uf,      ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $baseUrl = rtrim($this->appBaseUrl, '/');
        $url     = $baseUrl . '/radares?uf=' . urlencode($uf);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:20px">
            <p>Olá, <strong>{$nome}</strong>!</p>
            <p>
                Foram adicionados <strong>{$quantidade} novo(s) radar(es)</strong>
                no estado <strong>{$ufSafe}</strong> nos últimos 30 dias.
            </p>
            <p>
                <a href="{$url}" style="background:#1a73e8;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block">
                    Ver radares de {$ufSafe}
                </a>
            </p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0">
            <p style="font-size:12px;color:#888">Esta é uma notificação automática do ToolboxWaze. Não responda este e-mail.</p>
        </body>
        </html>
        HTML;
    }

    private function parseFrom(): Address
    {
        $raw = trim($this->mailerFrom);

        if (preg_match('/^(.+?)\s*<([^>]+)>\s*$/', $raw, $m)) {
            return new Address(trim($m[2]), trim($m[1]));
        }

        return new Address($raw ?: 'noreply@toolboxwaze.com.br');
    }
}
