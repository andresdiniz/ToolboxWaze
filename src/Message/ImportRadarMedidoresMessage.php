<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem que dispara a importação dos medidores RBMLQ de um único estado.
 *
 * O handler itera por todos os estados da tabela brazilian_state
 * e despacha uma mensagem por estado — isso permite paralelismo
 * futuro se você adicionar um transporte com workers reais.
 *
 * Uso direto (sem fila):
 *   php bin/console app:import-radar-medidores
 */
final class ImportRadarMedidoresMessage
{
    public const BASE_URL = 'https://servicos.rbmlq.gov.br/dados-abertos/%s/medidores.json';

    public function __construct(
        /** Sigla do estado: AC, AL, AM ... SP */
        public readonly string $uf,
    ) {
    }

    public function getUrl(): string
    {
        return sprintf(self::BASE_URL, strtoupper($this->uf));
    }
}
