<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\QueryBuilder;

/**
 * Filtros de estabelecimentos de saúde usados pela API e pelo script do WME.
 *
 * Chaves aceitas: municipio, link, ativo, coordenadas, busca.
 * Valor vazio/ausente = sem filtro naquele critério.
 */
final class SaudeFiltro
{
    public const LINK  = ['com_link', 'sem_link'];
    public const COORD = ['com_coord', 'sem_coord'];
    public const ATIVO = ['1', '0', 'todos'];

    /**
     * Normaliza e valida a entrada (query string ou JSON).
     *
     * @return array{municipio: ?string, link: ?string, ativo: ?string, coordenadas: ?string, busca: ?string}
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

        $opcao = static function (string $chave, array $validos) use ($texto): ?string {
            $valor = $texto($chave, 20);

            if ($valor === null) {
                return null;
            }

            $valor = strtolower($valor);

            if ($valor === 'true') {
                $valor = '1';
            } elseif ($valor === 'false') {
                $valor = '0';
            }

            if (!in_array($valor, $validos, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Filtro "%s" inválido. Use: %s.',
                    $chave,
                    implode(', ', $validos)
                ));
            }

            return $valor;
        };

        $busca = $texto('busca', 100);

        if ($busca !== null) {
            // % e _ são curingas do LIKE; tratados como espaço para não mudar a consulta.
            $busca = trim(str_replace(['%', '_'], ' ', $busca));
            $busca = $busca === '' ? null : $busca;
        }

        return [
            'municipio'   => $texto('municipio', 255),
            'link'        => $opcao('link', self::LINK),
            'ativo'       => $opcao('ativo', self::ATIVO),
            'coordenadas' => $opcao('coordenadas', self::COORD),
            'busca'       => $busca,
        ];
    }

    /** @param array<string, ?string> $filtros */
    public static function vazio(array $filtros): bool
    {
        return array_filter($filtros, static fn (?string $v): bool => $v !== null && $v !== '') === [];
    }

    /**
     * Aplica os filtros a um QueryBuilder cujo alias da entidade EstabelecimentoSaude é $alias.
     * Usa parâmetros prefixados com "f_" para não colidir com os da consulta original.
     *
     * @param array<string, ?string> $filtros resultado de normalizar()
     */
    public static function aplicar(
        QueryBuilder $qb,
        array $filtros,
        string $alias = 'e',
        bool $ignorarMunicipio = false,
    ): void {
        $a = $alias;

        if (!$ignorarMunicipio && ($filtros['municipio'] ?? null) !== null) {
            $qb->andWhere("$a.municipio = :f_municipio")->setParameter('f_municipio', $filtros['municipio']);
        }

        if (($filtros['busca'] ?? null) !== null) {
            $qb->andWhere("($a.nomeFantasia LIKE :f_busca OR $a.cnes LIKE :f_busca OR $a.logradouro LIKE :f_busca)")
                ->setParameter('f_busca', '%' . $filtros['busca'] . '%');
        }

        if (($filtros['ativo'] ?? null) === '1') {
            $qb->andWhere("$a.ativo = true");
        } elseif (($filtros['ativo'] ?? null) === '0') {
            $qb->andWhere("$a.ativo = false");
        }

        if (($filtros['link'] ?? null) === 'sem_link') {
            $qb->andWhere("($a.linkWaze IS NULL OR TRIM($a.linkWaze) = '')");
        } elseif (($filtros['link'] ?? null) === 'com_link') {
            $qb->andWhere("($a.linkWaze IS NOT NULL AND TRIM($a.linkWaze) <> '')");
        }

        $coordenadasValidas = "($a.latitude IS NOT NULL AND $a.longitude IS NOT NULL"
            . " AND $a.latitude <> 0 AND $a.longitude <> 0"
            . " AND $a.latitude BETWEEN -90 AND 90 AND $a.longitude BETWEEN -180 AND 180)";

        if (($filtros['coordenadas'] ?? null) === 'com_coord') {
            $qb->andWhere($coordenadasValidas);
        } elseif (($filtros['coordenadas'] ?? null) === 'sem_coord') {
            $qb->andWhere('NOT ' . $coordenadasValidas);
        }
    }
}
