<?php

declare(strict_types=1);

namespace App\Email\Enum;

enum EmailStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SENT = 'sent';
    case FAILED = 'failed';
}
