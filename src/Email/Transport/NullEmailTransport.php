<?php

declare(strict_types=1);

namespace App\Email\Transport;

use App\Email\Contract\EmailTransportInterface;
use App\Email\DTO\EmailPayload;

/**
 * Transporte nulo: nao envia e-mail.
 * Mantem o contrato EmailTransportInterface sem dependencia externa.
 */
final class NullEmailTransport implements EmailTransportInterface
{
    public function send(EmailPayload $payload): ?string
    {
        return null;
    }
}
