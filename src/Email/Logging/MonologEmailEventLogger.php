<?php

declare(strict_types=1);

namespace App\Email\Logging;

use App\Email\Contract\EmailEventLoggerInterface;
use App\Email\DTO\EmailPayload;
use App\Email\DTO\EmailSendResult;
use Psr\Log\LoggerInterface;

final readonly class MonologEmailEventLogger implements EmailEventLoggerInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function recordAttempt(EmailPayload $payload, EmailSendResult $result): void
    {
        $context = [
            'recipient' => $payload->to,
            'subject' => $payload->subject,
            'successful' => $result->successful,
            'provider_message_id' => $result->providerMessageId,
            'error' => $result->errorMessage,
        ];

        if ($result->successful) {
            $this->logger->info('E-mail transacional enviado.', $context);
            return;
        }

        $this->logger->error('Falha no envio de e-mail transacional.', $context);
    }
}
