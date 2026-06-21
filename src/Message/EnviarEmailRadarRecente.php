<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem assíncrona: notifica um usuário sobre novos radares inseridos em uma UF.
 *
 * @param string   $siglaUf         Sigla do estado (ex: "SP")
 * @param int      $userId          ID do usuário destinatário
 * @param int      $quantidadeNovos Quantidade de novos radares inseridos
 * @param int[]    $radarIds        IDs exatos dos novos radares (opcional).
 *                                  Quando informado, o handler exibe links individuais.
 *                                  Quando vazio, lista os recentes da UF via query.
 */
final readonly class EnviarEmailRadarRecente
{
    public function __construct(
        public readonly string $siglaUf,
        public readonly int    $userId,
        public readonly int    $quantidadeNovos,
        /** @var int[] */
        public readonly array  $radarIds = [],
    ) {}
}
