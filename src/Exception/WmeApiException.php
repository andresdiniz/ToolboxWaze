<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Erro da API WME com código estável para o userscript decidir o que fazer
 * (ex.: token_desconhecido, email_nao_confirmado, validacao).
 */
final class WmeApiException extends HttpException
{
    /** @param array<string,mixed> $extra campos adicionais do JSON de resposta */
    public function __construct(
        int $statusCode,
        private readonly string $codigo,
        string $message,
        private readonly array $extra = [],
    ) {
        parent::__construct($statusCode, $message);
    }

    public function getCodigo(): string { return $this->codigo; }

    /** @return array<string,mixed> */
    public function getExtra(): array { return $this->extra; }
}
