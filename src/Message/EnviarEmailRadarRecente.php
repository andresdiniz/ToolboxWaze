<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem Messenger para notificação assíncrona de radares recentes por UF.
 *
 * Disparada ao final do ImportRadarCommand sempre que houver inserções
 * novas em uma determinada UF. O processamento (envio do e-mail) é feito
 * de forma assíncrona pelo EnviarEmailRadarRecenteHandler, garantindo que
 * o processo de importação não seja bloqueado pelo volume de usuários.
 *
 * @see EnviarEmailRadarRecenteHandler
 */
final class EnviarEmailRadarRecente
{
    public function __construct(
        /** Sigla da UF onde os radares foram inseridos (ex: "SP") */
        public readonly string $siglaUf,

        /** ID do User que deve receber o aviso */
        public readonly int $userId,

        /** Quantidade de novos radares inseridos nesta UF no import atual */
        public readonly int $quantidadeNovos,
    ) {}
}
