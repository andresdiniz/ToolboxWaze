<?php

declare(strict_types=1);

namespace App\Email\MessageHandler;

use App\Email\Message\SendEmailMessage;
use App\Email\Service\EmailDeliveryService;
use App\Email\Service\EmailNotificationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendEmailMessageHandler
{
    public function __construct(
        private EmailNotificationService $notificationService,
        private EmailDeliveryService $deliveryService,
    ) {
    }

    public function __invoke(SendEmailMessage $message): void
    {
        $payload = $this->notificationService->buildPayload(
            $message->to,
            $message->subject,
            $message->template,
            $message->context,
            $message->name,
            $message->text,
        );

        $result = $this->deliveryService->deliver($payload);
        if (!$result->successful) {
            throw new \RuntimeException($result->errorMessage ?? 'Falha no envio do e-mail.');
        }
    }
}
