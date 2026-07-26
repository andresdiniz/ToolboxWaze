<?php

declare(strict_types=1);

namespace App\Message;

final class ImportRadaresMessage
{
    public function __construct(
        public readonly string $uf,
        public readonly bool   $skipWaze,
        public readonly string $logFile,
        public readonly string $token,
    ) {}
}
