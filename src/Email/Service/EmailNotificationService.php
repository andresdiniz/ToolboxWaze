<?php

declare(strict_types=1);

namespace App\Email\Service;

use App\Email\Contract\EmailTransportInterface;
use App\Email\DTO\EmailPayload;
use Twig\Environment;

final readonly class EmailNotificationService
{
    public function __construct(
        private EmailTransportInterface $transport,
        private Environment $twig,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function buildPayload(string $to, string $subject, string $template, array $context = [], ?string $name = null, ?string $text = null): EmailPayload
    {
        return new EmailPayload($to, $subject, $this->twig->render($template, $context), $text, $name);
    }

    /** @param array<string, mixed> $context */
    public function sendTemplate(string $to, string $subject, string $template, array $context = [], ?string $name = null, ?string $text = null): ?string
    {
        return $this->transport->send($this->buildPayload($to, $subject, $template, $context, $name, $text));
    }
}
