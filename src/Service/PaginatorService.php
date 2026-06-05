<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Elimina a duplicação do padrão page → offset → total → pages
 * encontrado em RadarController, SolicitacaoController, AuditoriaController etc.
 */
final class PaginatorService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Executa a query de dados paginada e retorna metadados + linhas.
     *
     * @param  string  $dataQuery  Query SELECT completa (sem LIMIT/OFFSET)
     * @param  string  $countQuery Query SELECT COUNT(*) equivalente
     * @param  array   $params     Parâmetros compartilhados pelas duas queries
     * @param  int     $page       Página solicitada (min 1)
     * @param  int     $perPage    Itens por página
     * @return array{rows: list<array>, total: int, pages: int, page: int, offset: int}
     */
    public function paginate(
        string $dataQuery,
        string $countQuery,
        array  $params,
        int    $page,
        int    $perPage
    ): array {
        $total  = (int) $this->db->fetchOne($countQuery, $params);
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $rows = $this->db->fetchAllAssociative(
            $dataQuery . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return compact('rows', 'total', 'pages', 'page', 'offset');
    }
}
