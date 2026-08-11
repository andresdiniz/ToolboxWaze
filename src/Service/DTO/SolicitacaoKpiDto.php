<?php
declare(strict_types=1);

namespace App\Service\DTO;

class SolicitacaoKpiDto
{
    public function __construct(
        public readonly int $total,
        public readonly int $atendidas,
        public readonly int $pendentes,
        public readonly int $recusadas,
        public readonly array $porTipo,
    ) {}
}