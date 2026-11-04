<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Valida e normaliza o snapshot do Place do WME enviado pelo userscript.
 *
 * Só campos conhecidos são mantidos, com tipos e tamanhos limitados:
 * o corpo da requisição nunca é gravado como veio.
 */
final class WazeSnapshotNormalizer
{
    private const MAX_CATEGORIAS = 20;

    /**
     * @return array<string, mixed>|null null quando $bruto é null
     *
     * @throws \InvalidArgumentException quando o snapshot é inválido
     */
    public static function normalizar(mixed $bruto): ?array
    {
        if ($bruto === null) {
            return null;
        }

        if (!is_array($bruto) || array_is_list($bruto)) {
            throw new \InvalidArgumentException('O campo waze deve ser um objeto.');
        }

        $id = self::texto($bruto['id'] ?? null, 120);

        if ($id === null || !preg_match('/^[A-Za-z0-9._:-]{1,120}$/', $id)) {
            throw new \InvalidArgumentException('O campo waze.id é obrigatório e deve ser um ID de Place válido.');
        }

        if (($bruto['aprovado'] ?? null) === false) {
            throw new \InvalidArgumentException('O Place informado ainda não foi aprovado no WME.');
        }

        $capturadoEm = self::texto($bruto['capturadoEm'] ?? null, 40);

        if ($capturadoEm !== null) {
            try {
                $capturadoEm = (new \DateTimeImmutable($capturadoEm))->format(DATE_ATOM);
            } catch (\Exception) {
                $capturadoEm = null;
            }
        }

        $endereco = is_array($bruto['enderecoSdk'] ?? null) ? $bruto['enderecoSdk'] : null;

        return [
            'id'                => $id,
            'nome'              => self::texto($bruto['nome'] ?? null, 255),
            'categorias'        => self::lista($bruto['categorias'] ?? null, 100),
            'categorias_nomes'  => self::lista($bruto['categoriasNomes'] ?? null, 255),
            'telefone'          => self::texto($bruto['telefone'] ?? null, 50),
            'url'               => self::texto($bruto['url'] ?? null, 500),
            'endereco'          => self::texto($bruto['endereco'] ?? null, 500),
            'endereco_sdk'      => $endereco === null ? null : [
                'rua'    => self::texto($endereco['rua'] ?? null, 255),
                'numero' => self::texto($endereco['numero'] ?? null, 50),
                'cidade' => self::texto($endereco['cidade'] ?? null, 255),
                'estado' => self::texto($endereco['estado'] ?? null, 255),
                'pais'   => self::texto($endereco['pais'] ?? null, 255),
            ],
            'coordenadas'       => self::coordenadas($bruto['coordenadas'] ?? null),
            'aprovado'          => is_bool($bruto['aprovado'] ?? null) ? $bruto['aprovado'] : null,
            'origem'            => self::texto($bruto['origem'] ?? null, 30),
            'capturado_em'      => $capturadoEm,
        ];
    }

    private static function texto(mixed $valor, int $max): ?string
    {
        if (!is_string($valor) && !is_int($valor) && !is_float($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $max);
    }

    /** @return list<string> */
    private static function lista(mixed $valor, int $maxItem): array
    {
        if (!is_array($valor)) {
            return [];
        }

        $saida = [];

        foreach (array_slice(array_values($valor), 0, self::MAX_CATEGORIAS) as $item) {
            $texto = self::texto($item, $maxItem);

            if ($texto !== null) {
                $saida[] = $texto;
            }
        }

        return $saida;
    }

    /** @return array{lat: float, lon: float}|null */
    private static function coordenadas(mixed $valor): ?array
    {
        if (!is_array($valor) || !is_numeric($valor['lat'] ?? null) || !is_numeric($valor['lon'] ?? null)) {
            return null;
        }

        $lat = (float) $valor['lat'];
        $lon = (float) $valor['lon'];

        if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }

        return ['lat' => $lat, 'lon' => $lon];
    }
}
