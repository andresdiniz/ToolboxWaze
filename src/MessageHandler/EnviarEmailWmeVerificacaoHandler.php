<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\WmeDispositivo;
use App\Message\EnviarEmailWmeVerificacao;
use App\Service\EmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class EnviarEmailWmeVerificacaoHandler
{
    public function __construct(
        private readonly EmailSender            $emailSender,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $emailQueueLogger,
    ) {}

    public function __invoke(EnviarEmailWmeVerificacao $message): void
    {
        $context = ['tipo' => 'wme_verificacao', 'dispositivo_id' => $message->dispositivoId];

        $device = $this->em->find(WmeDispositivo::class, $message->dispositivoId);
        if (!$device instanceof WmeDispositivo || !$device->isPendente()) {
            $this->emailQueueLogger->warning('EnviarEmailWmeVerificacao: dispositivo inexistente ou não pendente', $context);
            return;
        }

        $user = $device->getUser();
        if ($user->isRejected()) {
            $this->emailQueueLogger->warning('EnviarEmailWmeVerificacao: usuário rejeitado, e-mail não enviado', $context);
            return;
        }

        $confirmUrl = $this->emailSender->baseUrl()
            . '/api/wme/confirmar/' . $device->getId() . '/' . $message->verificador;

        $this->emailSender->sendTemplated(
            $user->getEmail(),
            $user->getName(),
            '[ToolboxWaze] Confirme seu e-mail para usar o script do WME',
            'emails/wme_verificacao.html.twig',
            [
                'user'       => $user,
                'apelido'    => $device->getApelidoDeclarado() ?? $user->getWazeNickname(),
                'confirmUrl' => $confirmUrl,
                'expiraEm'   => $device->getVerificacaoExpiraEm(),
            ]
        );

        $this->emailQueueLogger->info('Email [wme_verificacao] enviado', array_merge($context, [
            'destinatario' => $user->getEmail(),
        ]));
    }
}
