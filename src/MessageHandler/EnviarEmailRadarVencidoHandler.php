<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnviarEmailRadarVencido;
use App\Repository\RadarMedidorRepository;
use App\Repository\UserRepository;
use App\Service\EmailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Envia aviso de radar vencido (data_fim > 30 dias atrás) para editores.
 */
#[AsMessageHandler]
final class EnviarEmailRadarVencidoHandler
{
    public function __construct(
        private readonly EmailSender           $emailSender,
        private readonly RadarMedidorRepository $radarRepo,
        private readonly UserRepository        $userRepo,
        private readonly LoggerInterface       $emailQueueLogger,
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

            if (!in_array('ROLE_EDITOR', (array) $editor->getRoles(), true)) {
                $this->emailQueueLogger->warning('EnviarEmailRadarVencido: destinatário não é ROLE_EDITOR', array_merge($context, [
                    'roles' => $editor->getRoles(),
                ]));
                return;
            }

            $this->emailSender->send(
                $editor->getEmail(),
                $editor->getNome() ?? $editor->getEmail(),
                '[ToolboxWaze] Radar vencido há mais de 30 dias — ação necessária',
                $this->buildTemplate($radar, $editor)
            );

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
        $baseUrl = $this->emailSender->baseUrl();

        return <<<HTML
        <p>Olá, <strong>{$nomeEditor}</strong>!</p>
        <p>O radar <strong>{$codigo}</strong> está com a data de vigência vencida
           (<strong>{$dataFim}</strong>) há mais de 30 dias.</p>
        <p>Por favor, atualize ou remova este registro no sistema:</p>
        <p><a href="{$baseUrl}/admin">Acessar o painel</a></p>
        <p>Esta é uma notificação automática do ToolboxWaze.</p>
        HTML;
    }
}
