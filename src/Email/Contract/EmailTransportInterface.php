<?php

declare(strict_types=1);

namespace App\Email\Contract;

use App\Email\DTO\EmailPayload;

interface EmailTransportInterface
{
    /** @return string|null External provider message identifier. */
    public function send(EmailPayload $payload): ?string;
}
