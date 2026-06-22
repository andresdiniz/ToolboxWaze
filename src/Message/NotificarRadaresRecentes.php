<?php

namespace App\Message;

final readonly class NotificarRadaresRecentes
{
    /**
     * @param string             $siglaUf         UF dos radares inseridos (ex: "SP")
     * @param string             $nomeEstado      Nome completo para exibir no e-mail (ex: "São Paulo")
     * @param int                $userId          ID do usuário que receberá o e-mail
     * @param int                $quantidadeNovos Total de novos radares nessa UF nesse run
     * @param \DateTimeImmutable $dataImport      Data/hora do import para exibição no e-mail
     */
    public function __construct(
        public readonly string             $siglaUf,
        public readonly string             $nomeEstado,
        public readonly int                $userId,
        public readonly int                $quantidadeNovos,
        public readonly \DateTimeImmutable $dataImport,
    ) {}
}
