<?php

declare(strict_types=1);

namespace App\Email\Message;

final readonly class SendEmailMessage
{
    /** @param array<string, mixed> $context */
    public function __construct(public string $to, public string $subject, public string $template, public array $context = [], public ?string $name = null, public ?string $text = null, public ?string $type = null)
    {
    }
}
