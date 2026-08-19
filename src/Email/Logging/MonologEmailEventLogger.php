<?php

declare(strict_types=1);

namespace App\Email\Logging;

use App\Email\Contract\EmailEventLoggerInterface;
use Psr\Log\LoggerInterface;

final class MonologEmailEventLogger implements EmailEventLoggerInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function log(string $event, array $context = []): void
    {
        $this->logger->info('[Email Event] ' . $event, $context);
    }
}