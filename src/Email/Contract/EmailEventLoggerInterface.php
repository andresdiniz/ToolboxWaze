<?php

declare(strict_types=1);

namespace App\Email\Contract;

use App\Email\DTO\EmailPayload;
use App\Email\DTO\EmailSendResult;

interface EmailEventLoggerInterface
{
    public function recordAttempt(EmailPayload $payload, EmailSendResult $result): void;
}
