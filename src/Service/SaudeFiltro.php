<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\QueryBuilder;

/**
 * Filtros de estabelecimentos de saúde usados pela API e pelo script do WME.
 *
 * Chaves: tipo, municipio e (só compatibilidade da API) ativo.
 * A UF não é filtro aqui: já vem na rota. Valor vazio/ausente = sem filtro.
 */
final class SaudeFiltro
{
    public const ATIVO = ['1', '0', 'todos'];

    /**
     * @return array{tipo: ?string, municipio: ?string, ativo: ?string}
     *
     * @throws \InvalidArgumentException quando algum valor é inválido
     */
    public static function normalizar(mixed $bruto): array
    {
        $bruto = is_array($bruto) ? $bruto : [];

        $texto = static function (string $chave, int $max) use ($bruto): ?string {
            $valor = $bruto[$chave] ?? null;

            if ($valor === null || is_array($valor) || is_object($valor)) {
                return null;
            }

            $valor = trim((string) $valor);

            if ($valor === '') {
                return null;
            }

            if (mb_strlen($valor) > $max) {
                throw new \InvalidArgumentException(sprintf('Filtro "%s": tamanho máximo %d.', $chave, $max));
            }

            return $valor;
        };

        $ativo = $texto('ativo', 10);

        if ($ativo !== null) {
            $ativo = strtolower($ativo);
            $ativo = ['true' => '1', 'false' => '0'][$ativo] ?? $ativo;

            if (!in_array($ativo, self::ATIVO, true)) {
                throw new \InvalidArgumentException('Filtro "ativo" inválido. Use: ' . implode(', ', self::ATIVO) . '.');
            }
        }

        return [
            'tipo'      => $texto('tipo', 255),
            'municipio' => $texto('municipio', 255),
            'ativo'     => $ativo,
        ];
    }

    /** @param array<string, ?string> $filtros */
    public static function vazio(array $filtros): bool
    {
        return array_filter($filtros, static fn (?string $v): bool => $v !== null && $v !== '') === [];
    }

    /**
     * Aplica os filtros a um QueryBuilder cujo alias de EstabelecimentoSaude é $alias.
     * Parâmetros prefixados com "f_" para não colidir com os da consulta original.
     *
     * @param array<string, ?string> $filtros resultado de normalizar()
     * @param list<string>           $ignorar chaves a não aplicar (usado nas facetas)
     */
    public static function aplicar(QueryBuilder $qb, array $filtros, string $alias = 'e', array $ignorar = []): void
    {
        $a = $alias;

        if (($filtros['tipo'] ?? null) !== null && !in_array('tipo', $ignorar, true)) {
            $qb->andWhere("LOWER($a.tipo) = LOWER(:f_tipo)")->setParameter('f_tipo', $filtros['tipo']);
        }

        if (($filtros['municipio'] ?? null) !== null && !in_array('municipio', $ignorar, true)) {
            $qb->andWhere("$a.municipio = :f_municipio")->setParameter('f_municipio', $filtros['municipio']);
        }

        if (($filtros['ativo'] ?? null) === '1') {
            $qb->andWhere("$a.ativo = true");
        } elseif (($filtros['ativo'] ?? null) === '0') {
            $qb->andWhere("$a.ativo = false");
        }
    }
}