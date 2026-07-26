<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Paginação DBAL com cache de COUNT via cache.app.
 *
 * O COUNT(*) é cacheado 60 s com chave baseada no hash da query + params,
 * eliminando a query #4 repetida a cada navegação entre páginas.
 */
final class PaginatorService
{
    public function __construct(
        private readonly Connection     $db,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache
    ) {}

    /**
     * @param  string  $dataQuery   SELECT sem LIMIT/OFFSET
     * @param  string  $countQuery  SELECT COUNT(*) correspondente
     * @param  array   $params      Parâmetros compartilhados pelas duas queries
     * @param  int     $page        Página atual (1-based)
     * @param  int     $perPage     Itens por página
     * @return array{rows:list<array>,total:int,pages:int,page:int,offset:int}
     */
    public function paginate(
        string $dataQuery,
        string $countQuery,
        array  $params,
        int    $page    = 1,
        int    $perPage = 50
    ): array {
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        // Cache do COUNT 60 s — chave determinística por query+params
        $countKey = 'paginator_count_' . md5($countQuery . serialize($params));

        $total = (int) $this->cache->get($countKey, function (ItemInterface $item) use ($countQuery, $params): int {
            $item->expiresAfter(60);
            return (int) $this->db->fetchOne($countQuery, $params);
        });

        $pages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
        $page  = min($page, max(1, $pages));

        $rows = $this->db->fetchAllAssociative(
            $dataQuery . " LIMIT $perPage OFFSET $offset",
            $params
        );

        return [
            'rows'   => $rows,
            'total'  => $total,
            'pages'  => $pages,
            'page'   => $page,
            'offset' => $offset,
        ];
    }
}
