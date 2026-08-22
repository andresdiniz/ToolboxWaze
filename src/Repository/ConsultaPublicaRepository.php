<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EscolaInep;
use App\Entity\FuelResellerRaw;
use App\Entity\RadarMedidor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

final class ConsultaPublicaRepository extends ServiceEntityRepository
{
    private const TYPES = ['radar', 'escola', 'posto'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RadarMedidor::class);
    }

    public function findMunicipios(string $tipo, string $uf): array
    {
        $tipo = strtolower(trim($tipo));
        $uf = strtoupper(trim($uf));

        if (!in_array($tipo, self::TYPES, true) || !preg_match('/^[A-Z]{2}$/', $uf)) {
            return [];
        }

        [$entityClass, $field] = match ($tipo) {
            'radar' => [RadarMedidor::class, 'municipio'],
            'escola' => [EscolaInep::class, 'municipio'],
            'posto' => [FuelResellerRaw::class, 'municipio'],
        };

        $alias = 'record';
        $query = $this->getEntityManager()->createQueryBuilder()
            ->select("DISTINCT {$alias}.{$field} AS municipio")
            ->from($entityClass, $alias)
            ->andWhere("UPPER({$alias}.uf) = :uf")
            ->andWhere("{$alias}.{$field} IS NOT NULL")
            ->andWhere("{$alias}.{$field} <> ''")
            ->setParameter('uf', $uf)
            ->orderBy("{$alias}.{$field}", 'ASC');

        return array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) $row['municipio']),
            $query->getQuery()->getArrayResult()
        )));
    }

    public function search(string $tipo, array $filters, int $page = 1, int $limit = 20): array
    {
        $tipo = strtolower(trim($tipo));

        if (!in_array($tipo, self::TYPES, true)) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'limit' => $limit];
        }

        $page = max(1, $page);
        $limit = min(50, max(1, $limit));

        [$entityClass, $alias] = match ($tipo) {
            'radar' => [RadarMedidor::class, 'radar'],
            'escola' => [EscolaInep::class, 'escola'],
            'posto' => [FuelResellerRaw::class, 'posto'],
        };

        $query = $this->getEntityManager()->createQueryBuilder()->from($entityClass, $alias);
        $this->applyFilters($query, $alias, $filters, $tipo);
        $this->selectFields($query, $tipo, $alias);

        $countQuery = $this->getEntityManager()->createQueryBuilder()
            ->select("COUNT(DISTINCT {$alias}.id)")
            ->from($entityClass, $alias);
        $this->applyFilters($countQuery, $alias, $filters, $tipo);

        $total = (int) $countQuery->getQuery()->getSingleScalarResult();
        $items = $query
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return compact('items', 'total', 'page', 'limit');
    }

    private function applyFilters(QueryBuilder $query, string $alias, array $filters, string $tipo): void
    {
        foreach (['uf', 'municipio'] as $field) {
            if (!empty($filters[$field])) {
                $query
                    ->andWhere("UPPER({$alias}.{$field}) = UPPER(:{$field})")
                    ->setParameter($field, trim((string) $filters[$field]));
            }
        }

        if (empty($filters['q'])) {
            return;
        }

        if ($tipo === 'radar') {
            $query->leftJoin($alias . '.faixas', 'faixaBusca');
            $expressions = [
                "LOWER({$alias}.municipio) LIKE LOWER(:q)",
                "LOWER({$alias}.logradouro) LIKE LOWER(:q)",
                "LOWER({$alias}.numeroSerie) LIKE LOWER(:q)",
                'LOWER(faixaBusca.numeroInmetro) LIKE LOWER(:q)',
            ];
        } elseif ($tipo === 'posto') {
            $expressions = [
                "LOWER({$alias}.razaoSocial) LIKE LOWER(:q)",
                "LOWER({$alias}.nomeFantasia) LIKE LOWER(:q)",
                "LOWER({$alias}.municipio) LIKE LOWER(:q)",
                "LOWER({$alias}.bandeira) LIKE LOWER(:q)",
            ];
        } else {
            $expressions = [
                "LOWER({$alias}.escola) LIKE LOWER(:q)",
                "LOWER({$alias}.municipio) LIKE LOWER(:q)",
                "LOWER({$alias}.codigoInep) LIKE LOWER(:q)",
            ];
        }

        $query
            ->andWhere($query->expr()->orX(...$expressions))
            ->setParameter('q', '%' . trim((string) $filters['q']) . '%');
    }

    private function selectFields(QueryBuilder $query, string $tipo, string $alias): void
    {
        if ($tipo === 'radar') {
            $query
                ->leftJoin($alias . '.faixas', 'faixa')
                ->select(implode(', ', [
                    $alias . '.id AS id',
                    $alias . '.municipio AS municipio',
                    $alias . '.uf AS uf',
                    $alias . '.logradouro AS endereco',
                    $alias . '.tipoMedidor AS tipo',
                    $alias . '.nomeEmpresa AS nomeEmpresa',
                    $alias . '.cnpjEmpresa AS cnpjEmpresa',
                    $alias . '.marcaMedidor AS marcaMedidor',
                    $alias . '.modeloMedidor AS modeloMedidor',
                    $alias . '.numeroSerie AS numeroSerie',
                    $alias . '.capacidade AS capacidade',
                    $alias . '.situacao AS situacao',
                    $alias . '.dataVerificacao AS dataVerificacao',
                    $alias . '.dataUltimaVerificacao AS dataUltimaVerificacao',
                    $alias . '.dataValidade AS dataValidade',
                    $alias . '.dataVerificacaoEfetiva AS dataVerificacaoEfetiva',
                    $alias . '.dataLacre AS dataLacre',
                    $alias . '.lacre AS lacre',
                    $alias . '.numeroCertificado AS numeroCertificado',
                    $alias . '.orgaoVerificador AS orgaoVerificador',
                    'faixa.numeroInmetro AS numeroInmetro',
                    'faixa.numeroFaixa AS numeroFaixa',
                    'faixa.sentido AS sentido',
                    'faixa.velocidadeNominal AS velocidade',
                ]))
                ->addOrderBy($alias . '.municipio', 'ASC')
                ->addOrderBy('faixa.numeroFaixa', 'ASC');

            return;
        }

        if ($tipo === 'escola') {
            $fields = [
                $alias . '.id AS id',
                $alias . '.escola AS nome',
                $alias . '.codigoInep AS codigoInep',
                $alias . '.restricaoAtendimento AS restricaoAtendimento',
                $alias . '.municipio AS municipio',
                $alias . '.uf AS uf',
                $alias . '.localizacao AS localizacao',
                $alias . '.localidadeDiferenciada AS localidadeDiferenciada',
                $alias . '.categoriaAdministrativa AS categoriaAdministrativa',
                $alias . '.endereco AS endereco',
                $alias . '.telefone AS telefone',
                $alias . '.dependenciaAdministrativa AS dependenciaAdministrativa',
                $alias . '.categoriaEscolaPrivada AS categoriaEscolaPrivada',
                $alias . '.conveniada AS conveniada',
                $alias . '.regulamentacao AS regulamentacao',
                $alias . '.porte AS porte',
                $alias . '.etapasEnsino AS etapasEnsino',
                $alias . '.outrasOfertas AS outrasOfertas',
                $alias . '.latitude AS latitude',
                $alias . '.longitude AS longitude',
                $alias . '.linkAreaEscolar AS linkAreaEscolar',
            ];

            $query->select(implode(', ', $fields))->addOrderBy($alias . '.municipio', 'ASC');
            return;
        }

        $fields = [
            $alias . '.id AS id',
            $alias . '.codigoIsimp AS codigoIsimp',
            $alias . '.autorizacao AS autorizacao',
            $alias . '.dataPublicacao AS dataPublicacao',
            $alias . '.razaoSocial AS razaoSocial',
            $alias . '.cnpj AS cnpj',
            $alias . '.endereco AS endereco',
            $alias . '.complemento AS complemento',
            $alias . '.bairro AS bairro',
            $alias . '.cep AS cep',
            $alias . '.uf AS uf',
            $alias . '.municipio AS municipio',
            $alias . '.bandeira AS bandeira',
            $alias . '.dataVinculacao AS dataVinculacao',
            $alias . '.nomeFantasia AS nomeFantasia',
            $alias . '.importedAt AS importedAt',
            $alias . '.updatedAt AS updatedAt',
        ];

        $query
            ->select(implode(', ', $fields))
            ->addOrderBy($alias . '.municipio', 'ASC')
            ->addOrderBy($alias . '.nomeFantasia', 'ASC');
    }
}
