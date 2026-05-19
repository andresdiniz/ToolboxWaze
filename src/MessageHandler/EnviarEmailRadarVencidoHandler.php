<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnviarEmailRadarVencido;
use App\Repository\RadarMedidorRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envia aviso de radar vencido (data_fim > 30 dias atrás) para editores.
 *
 * O destinatário é sempre um usuário com ROLE_EDITOR — a seleção
 * de quais editores recebem o aviso é responsabilidade do serviço/command
 * que faz o dispatch (ex: app:notificar-radares-vencidos).
 *
 * Todos os eventos são registrados no canal 'email_queue'.
 */
#[AsMessageHandler]
final class EnviarEmailRadarVencidoHandler
{
    public function __construct(
        private readonly MailerInterface         $mailer,
        private readonly RadarMedidorRepository  $radarRepo,
        private readonly UserRepository          $userRepo,
        private readonly LoggerInterface         $emailQueueLogger,
        private readonly string                  $mailerFrom = '',
        private readonly string                  $appBaseUrl  = '',
    ) {}

    public function __invoke(EnviarEmailRadarVencido $message): void
    {
        $context = [
            'radar_id'  => $message->radarId,
            'editor_id' => $message->editorId,
        ];

        try {
            $radar = $this->radarRepo->find($message->radarId);
            if (!$radar) {
                $this->emailQueueLogger->warning('EnviarEmailRadarVencido: radar não encontrado', $context);
                return;
            }

            $editor = $this->userRepo->find($message->editorId);
            if (!$editor) {
                $this->emailQueueLogger->warning('EnviarEmailRadarVencido: editor não encontrado', $context);
                return;
            }

            // Garante que só editores recebem esse e-mail
            if (!in_array('ROLE_EDITOR', (array) $editor->getRoles(), true)) {
                $this->emailQueueLogger->warning('EnviarEmailRadarVencido: destinatário não é ROLE_EDITOR', array_merge($context, [
                    'roles' => $editor->getRoles(),
                ]));
                return;
            }

            $from = $this->parseFrom();

            $email = (new Email())
                ->from($from)
                ->to(new Address($editor->getEmail(), $editor->getNome() ?? $editor->getEmail()))
                ->subject('[ToolboxWaze] Radar vencido há mais de 30 dias — ação necessária')
                ->html($this->buildTemplate($radar, $editor));

            $this->mailer->send($email);

            $this->emailQueueLogger->info('Email radar_vencido enviado', array_merge($context, [
                'destinatario' => $editor->getEmail(),
                'radar_codigo' => method_exists($radar, 'getCodigo') ? $radar->getCodigo() : null,
                'data_fim'     => method_exists($radar, 'getDataFim') && $radar->getDataFim()
                    ? $radar->getDataFim()->format('Y-m-d')
                    : null,
            ]));

        } catch (\Throwable $e) {
            $this->emailQueueLogger->error('EnviarEmailRadarVencido: falha', array_merge($context, [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]));
            throw $e;
        }
    }

    private function buildTemplate(object $radar, object $editor): string
    {
        $nomeEditor = htmlspecialchars($editor->getNome() ?? $editor->getEmail(), ENT_QUOTES);
        $codigo     = htmlspecialchars(method_exists($radar, 'getCodigo') ? (string) $radar->getCodigo() : '#' . $radar->getId(), ENT_QUOTES);
        $dataFim    = (method_exists($radar, 'getDataFim') && $radar->getDataFim())
            ? $radar->getDataFim()->format('d/m/Y')
            : 'não informada';
        $baseUrl = rtrim($this->appBaseUrl, '/');

        return <<<HTML
        <p>Olá, <strong>{$nomeEditor}</strong>!</p>
        <p>O radar <strong>{$codigo}</strong> está com a data de vigência vencida
           (<strong>{$dataFim}</strong>) há mais de 30 dias.</p>
        <p>Por favor, atualize ou remova este registro no sistema:</p>
        <p><a href="{$baseUrl}/admin">Acessar o painel</a></p>
        <p>Esta é uma notificação automática do ToolboxWaze.</p>
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
