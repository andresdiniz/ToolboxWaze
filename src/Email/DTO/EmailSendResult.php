<?php

declare(strict_types=1);

namespace App\Email\DTO;

final readonly class EmailSendResult
{
    public function __construct(
        public bool $successful,
        public ?string $providerMessageId = null,
        public ?string $errorMessage = null,
    ) {
    }

    public static function success(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failure(string $errorMessage): self
    {
        return new self(false, null, $errorMessage);
    }
}
