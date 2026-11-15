<?php

declare(strict_types=1);

namespace App\Message;

/**
 * E-mail de confirmação do dispositivo do userscript WME.
 * Despachado de forma síncrona (o verificador nunca vai para a fila do banco).
 */
final class EnviarEmailWmeVerificacao
{
    public function __construct(
        public readonly int $dispositivoId,
        /** Verificador em claro (uso único, expira em 60 min). */
        public readonly string $verificador,
    ) {}
}
