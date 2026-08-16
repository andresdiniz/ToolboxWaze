<?php

declare(strict_types=1);

namespace App\Email\DTO;

final readonly class EmailPayload
{
    /** @param array<string, mixed> $headers */
    public function __construct(
        public string $to,
        public string $subject,
        public string $html,
        public ?string $text = null,
        public ?string $name = null,
        public array $headers = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(string $from): array
    {
        return [
            'from' => $from,
            'to' => [$this->name ? sprintf('%s <%s>', $this->name, $this->to) : $this->to],
            'subject' => $this->subject,
            'html' => $this->html,
            ...($this->text !== null ? ['text' => $this->text] : []),
            ...($this->headers !== [] ? ['headers' => $this->headers] : []),
        ];
    }
}
