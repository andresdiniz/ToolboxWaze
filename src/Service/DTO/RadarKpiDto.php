<?php
declare(strict_types=1);

namespace App\Service\DTO;

class RadarKpiDto
{
    public function __construct(
        public readonly int $total,
        public readonly int $aprovados,
        public readonly int $reprovados,
        public readonly int $vencidos,
        public readonly int $vencendo,
        public readonly int $comWaze,
        public readonly float $pctWaze,
    ) {}
}