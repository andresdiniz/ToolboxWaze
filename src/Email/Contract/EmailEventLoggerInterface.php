<?php

declare(strict_types=1);

namespace App\Email\Contract;

interface EmailEventLoggerInterface
{
    public function log(string $event, array $context = []): void;
}