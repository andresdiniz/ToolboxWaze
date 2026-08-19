<?php

declare(strict_types=1);

namespace App\Email\Transport;

use App\Email\Contract\EmailTransportInterface;
use App\Email\DTO\EmailPayload;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ResendEmailTransport implements EmailTransportInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
        private string $from,
    ) {
    }

    public function send(EmailPayload $payload): ?string
    {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('RESEND_API_KEY não está configurada.');
        }

        if (trim($this->from) === '' || !str_contains($this->from, '@')) {
            throw new RuntimeException('MAILER_FROM_EMAIL/MAILER_FROM_NAME não estão configurados corretamente.');
        }

        $response = $this->httpClient->request('POST', 'https://api.resend.com/emails', [
            'auth_bearer' => $this->apiKey,
            'headers' => ['Accept' => 'application/json'],
            'json' => $payload->toArray($this->from),
        ]);

        $data = $response->toArray();

        return isset($data['id']) ? (string) $data['id'] : null;
    }
}