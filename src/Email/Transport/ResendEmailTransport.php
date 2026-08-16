<?php

declare(strict_types=1);

namespace App\Email\Transport;

use App\Email\Contract\EmailTransportInterface;
use App\Email\DTO\EmailPayload;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ResendEmailTransport implements EmailTransportInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
        private string $from,
    ) {
    }

    public function send(EmailPayload $payload): ?string
    {
        $response = $this->httpClient->request('POST', 'https://api.resend.com/emails', [
            'auth_bearer' => $this->apiKey,
            'json' => $payload->toArray($this->from),
        ]);

        $data = $response->toArray();

        return isset($data['id']) ? (string) $data['id'] : null;
    }
}
