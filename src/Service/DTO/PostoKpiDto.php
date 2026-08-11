<?php
declare(strict_types=1);

namespace App\Service\DTO;

class PostoKpiDto
{
    public function __construct(
        public readonly int $total,
        public readonly int $comWaze,
        public readonly int $semWaze,
        public readonly float $pct,
    ) {}
}