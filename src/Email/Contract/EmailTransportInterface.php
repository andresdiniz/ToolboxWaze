<?php

declare(strict_types=1);

namespace App\Email\Contract;

use App\Email\DTO\EmailPayload;

interface EmailTransportInterface
{
    public function send(EmailPayload $payload): ?string;
}