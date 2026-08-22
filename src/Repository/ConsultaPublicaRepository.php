<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EscolaInep;
use App\Entity\FuelResellerRaw;
use App\Entity\RadarFaixa;
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
        if (!in_array($tipo, self::TYPES, true) || !preg_match('/^[A-Z]{2}$/', $uf)) return [];

        [$entity, $field] = match ($tipo) {
            'radar' => [RadarMedidor::class, 'municipio'],
            'escola' => [EscolaInep::class, 'municipio'],
            'posto' => [FuelResellerRaw::class, 'municipio'],
        };

        $alias = 'record';
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select(sprintf('DISTINCT %s.%s AS municipio', $alias, $field))
            ->from($entity, $alias)
            ->andWhere(sprintf('UPPER(%s.uf) = :uf', $alias))
            ->andWhere(sprintf('%s.%s IS NOT NULL', $alias, $field))
            ->andWhere(sprintf("%s.%s <> ''", $alias, $field))
            ->setParameter('uf', $uf)
            ->orderBy(sprintf('%s.%s', $alias, $field), 'ASC');

        return array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) $row['municipio']),
            $qb->getQuery()->getArrayResult()
        )));
    }

    public function search(string $tipo, array $filters, int $page = 1, int $limit = 20): array
    {
        $tipo = strtolower(trim($tipo));
        if (!in_array($tipo, self::TYPES, true)) return ['items' => [], 'total' => 0, 'page' => 1, 'limit' => $limit];

        $page = max(1, $page);
        $limit = min(50, max(1, $limit));
        [$entity, $alias] = match ($tipo) {
            'radar' => [RadarMedidor::class, 'radar'],
            'escola' => [EscolaInep::class, 'escola'],
            'posto' => [FuelResellerRaw::class, 'posto'],
        };

        $qb = $this->getEntityManager()->createQueryBuilder()->from($entity, $alias);
        $this->applyFilters($qb, $alias, $filters, $tipo);
        $this->selectFields($qb, $tipo, $alias);

        $countQb = $this->getEntityManager()->createQueryBuilder()
            ->select(sprintf('COUNT(DISTINCT %s.id)', $alias))
            ->from($entity, $alias);
        $this->applyFilters($countQb, $alias, $filters, $tipo);

        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getArrayResult();
        return compact('items', 'total', 'page', 'limit');
    }

    private function applyFilters(QueryBuilder $qb, string $alias, array $filters, string $tipo): void
    {
        foreach (['uf', 'municipio'] as $field) {
            if (!empty($filters[$field])) {
                $qb->andWhere(sprintf('UPPER(%s.%s) = UPPER(:%s)', $alias, $field, $field))
                    ->setParameter($field, trim((string) $filters[$field]));
            }
        }

        if (!empty($filters['q'])) {
            $searchExpressions = $tipo === 'radar'
                ? [sprintf('LOWER(%s.municipio) LIKE LOWER(:q)', $alias), sprintf('LOWER(%s.logradouro) LIKE LOWER(:q)', $alias), sprintf('LOWER(%s.numeroSerie) LIKE LOWER(:q)', $alias)]
                : [sprintf('LOWER(%s.nome) LIKE LOWER(:q)', $alias)];
            if ($tipo === 'radar') {
                $searchExpressions[] = 'LOWER(faixa.numeroInmetro) LIKE LOWER(:q)';
            }
            $qb->andWhere($qb->expr()->orX(...$searchExpressions))->setParameter('q', '%' . trim((string) $filters['q']) . '%');
        }
    }

    private function selectFields(QueryBuilder $qb, string $tipo, string $alias): void
    {
        if ($tipo === 'radar') {
            $qb->leftJoin($alias . '.faixas', 'faixa')
                ->select(implode(', ', [
                    $alias . '.id AS id',
                    $alias . '.municipio AS municipio',
                    $alias . '.uf AS uf',
                    $alias . '.logradouro AS endereco',
                    $alias . '.tipoMedidor AS tipo',
                    $alias . '.numeroSerie AS numeroSerie',
                    $alias . '.latitude AS latitude',
                    $alias . '.longitude AS longitude',
                    'faixa.numeroInmetro AS numeroInmetro',
                    'faixa.numeroFaixa AS numeroFaixa',
                    'faixa.sentido AS sentido',
                    'faixa.velocidadeNominal AS velocidade',
                ]))
                ->addOrderBy($alias . '.municipio', 'ASC')
                ->addOrderBy('faixa.numeroFaixa', 'ASC');
            return;
        }

        $fields = $tipo === 'escola'
            ? [$alias.'.id AS id', $alias.'.nome AS nome', $alias.'.municipio AS municipio', $alias.'.uf AS uf', $alias.'.endereco AS endereco', $alias.'.telefone AS telefone', $alias.'.latitude AS latitude', $alias.'.longitude AS longitude']
            : [$alias.'.id AS id', $alias.'.nome AS nome', $alias.'.municipio AS municipio', $alias.'.uf AS uf', $alias.'.endereco AS endereco', $alias.'.telefone AS telefone', $alias.'.latitude AS latitude', $alias.'.longitude AS longitude'];

        $qb->select(implode(', ', $fields))->addOrderBy($alias.'.municipio', 'ASC');
    }
}
