<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem Messenger para aviso de radar vencido (data_fim > 30 dias atrás).
 *
 * Destinatários: somente editores com ROLE_EDITOR que possuam dados
 * associados ao radar (ou todos os editores, conforme regra de negócio).
 *
 * Exemplo de uso:
 *   $bus->dispatch(new EnviarEmailRadarVencido($radarId, $editorId));
 *
 * Para disparar em lote (via command/cron):
 *   foreach ($editores as $editor) {
 *       $bus->dispatch(new EnviarEmailRadarVencido($radarId, $editor->getId()));
 *   }
 */
final class EnviarEmailRadarVencido
{
    public function __construct(
        /** ID do RadarMedidor vencido */
        public readonly int $radarId,

        /** ID do User editor que deve receber o aviso */
        public readonly int $editorId,
    ) {}
}
