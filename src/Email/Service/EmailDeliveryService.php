<?php

declare(strict_types=1);

namespace App\Email\Service;

use App\Email\Contract\EmailEventLoggerInterface;
use App\Email\Contract\EmailTransportInterface;
use App\Email\DTO\EmailPayload;
use App\Email\DTO\EmailSendResult;
use Throwable;

final readonly class EmailDeliveryService
{
    public function __construct(
        private EmailTransportInterface $transport,
        private ?EmailEventLoggerInterface $eventLogger = null,
    ) {
    }

    public function deliver(EmailPayload $payload): EmailSendResult
    {
        try {
            $result = EmailSendResult::success($this->transport->send($payload));
        } catch (Throwable $exception) {
            $result = EmailSendResult::failure($exception->getMessage());
        }

        $this->eventLogger?->recordAttempt($payload, $result);

        return $result;
    }
}
