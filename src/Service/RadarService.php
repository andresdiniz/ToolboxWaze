<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Encapsula toda a lógica de negócio, queries SQL e regras de validação
 * relacionadas a radares, desafogando o RadarController.
 *
 * Histórico de melhorias de performance:
 *  #P1 — getStats() usa QueryCacheProfile (DBAL result cache)
 *  #P2 — getShowData() usa QueryCacheProfile nas sub-queries estáticas
 *  #P3 — addPublicCacheHeaders() aplica Cache-Control nas páginas públicas
 *  #P4 — getStatsFull() funde Q3+Q5+Q6 em 1 única query (3 roundtrips → 1)
 *  #P5 — findPaginated() usa data_validade_date (coluna gerada) em vez de
 *         STR_TO_DATE() em runtime — elimina recálculo por linha
 */
final class RadarService
{
    /**
     * #P5 — coluna DATE gerada/persistida na migration Version20260726_ValidadeDate.
     * Usar r.data_validade_date no lugar de STR_TO_DATE(r.data_validade, '%d/%m/%Y')
     * elimina o recálculo em cada linha e permite uso de índice B-tree.
     * Manter VALIDADE_ISO_EXPR como fallback para ambientes sem a migration.
     */
    private const VALIDADE_COL      = 'r.data_validade_date';
    private const VALIDADE_ISO_EXPR = "STR_TO_DATE(r.data_validade, '%d/%m/%Y')";
    private const CACHE_TTL         = 3600; // 1 hora
    private const STATS_CACHE_TTL   = 300;  // 5 min

    /** @var array<string,string> */
    private array $camposEditaveis;

    public function __construct(
        private readonly Connection       $db,
        private readonly PaginatorService $paginator,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface   $cache,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir
    ) {
        $this->camposEditaveis = require $projectDir . '/config/radar_campos.php';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Leitura
    // ──────────────────────────────────────────────────────────────────────────

    public function findOrFail(int $id): array
    {
        $radar = $this->db->fetchAssociative(
            'SELECT r.*, r.data_validade_date AS data_validade_iso
             FROM radar_medidor r WHERE r.id = ?',
            [$id]
        );

        if (!$radar) {
            throw new \RuntimeException("Radar #{$id} não encontrado.", 404);
        }

        return $radar;
    }

    /**
     * Monta todos os dados necessários para renderizar radar/show.html.twig.
     *
     * #P2 — sub-queries estáticas (faixas, historico, absorbedRadares)
     *        cacheadas por 1h via QueryCacheProfile.
     */
    public function getShowData(int $id): array
    {
        $radar = $this->findOrFail($id);

        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $ha30 = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        $survivorRadar = null;
        if (!empty($radar['merged_into_id'])) {
            $survivorRadar = $this->db->fetchAssociative(
                'SELECT id, sigla_uf, municipio, logradouro FROM radar_medidor WHERE id = ?',
                [(int) $radar['merged_into_id']]
            ) ?: null;
        }

        $absorbedRadares = $this->db->executeCacheQuery(
            'SELECT id, sigla_uf, municipio, logradouro, merged_at, merged_by
             FROM radar_medidor WHERE merged_into_id = ? ORDER BY merged_at DESC',
            [$id], [],
            new QueryCacheProfile(self::CACHE_TTL, 'absorbed_' . $id)
        )->fetchAllAssociative();

        $wazeLink = $this->db->fetchAssociative(
            'SELECT wl.*, u1.email AS inserted_by_email, u2.email AS updated_by_email
             FROM radar_waze_link wl
             LEFT JOIN user u1 ON u1.id = wl.inserted_by
             LEFT JOIN user u2 ON u2.id = wl.updated_by
             WHERE wl.radar_medidor_id = ? ORDER BY wl.id DESC LIMIT 1',
            [$id]
        ) ?: null;

        $wazeLog = $this->db->fetchAllAssociative(
            'SELECT wll.*, u.email AS changed_by_email
             FROM radar_waze_link_log wll
             INNER JOIN radar_waze_link wl ON wl.id = wll.radar_waze_link_id
             LEFT JOIN user u ON u.id = wll.changed_by
             WHERE wl.radar_medidor_id = ? ORDER BY wll.changed_at DESC LIMIT 20',
            [$id]
        );

        $historico = $this->db->executeCacheQuery(
            'SELECT * FROM radar_historico WHERE radar_medidor_id = ? ORDER BY data_laudo DESC LIMIT 10',
            [$id], [],
            new QueryCacheProfile(self::CACHE_TTL, 'historico_' . $id)
        )->fetchAllAssociative();

        $faixas = $this->db->executeCacheQuery(
            'SELECT * FROM radar_faixa WHERE radar_medidor_id = ? ORDER BY numero_faixa',
            [$id], [],
            new QueryCacheProfile(self::CACHE_TTL, 'faixas_' . $id)
        )->fetchAllAssociative();

        return [
            'radar'           => $radar,
            'survivorRadar'   => $survivorRadar,
            'absorbedRadares' => $absorbedRadares,
            'wazeLink'        => $wazeLink,
            'wazeLog'         => $wazeLog,
            'historico'       => $historico,
            'faixas'          => $faixas,
            'hoje'            => $hoje,
            'em30'            => $em30,
            'ha30dias'        => $ha30,
            'wazeErrors'      => [],
            'wazeFormData'    => [],
        ];
    }

    /**
     * Retorna página de radares com filtros aplicados.
     *
     * #P5 — usa r.data_validade_date (coluna gerada) em vez de STR_TO_DATE().
     *        Elimina recálculo em cada linha e habilita uso do índice B-tree.
     *
     * @param  array{uf:string,municipio:string,resultado:string,tipo:string,validade:string,serie:string} $filters
     * @return array{rows:list<array>,total:int,pages:int,page:int,offset:int}
     */
    public function findPaginated(
        array  $filters,
        int    $page,
        int    $perPage,
        ?array $allowedUfs = null
    ): array {
        $vcol = self::VALIDADE_COL;  // r.data_validade_date
        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $ha30 = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        $where  = ['r.merged_into_id IS NULL'];
        $params = [];

        if ($allowedUfs !== null) {
            $placeholders = implode(',', array_fill(0, count($allowedUfs), '?'));
            $where[]      = "r.sigla_uf IN ($placeholders)";
            $params       = array_merge($params, $allowedUfs);
        }

        if ($filters['uf'] !== '') {
            $where[]  = 'r.sigla_uf = ?';
            $params[] = $filters['uf'];
        }
        if ($filters['municipio'] !== '') {
            $where[]  = 'r.municipio LIKE ?';
            $params[] = '%' . $filters['municipio'] . '%';
        }
        if ($filters['resultado'] !== '') {
            $where[]  = 'r.situacao = ?';
            $params[] = $filters['resultado'];
        }
        if ($filters['tipo'] !== '') {
            $where[]  = 'r.tipo_medidor = ?';
            $params[] = $filters['tipo'];
        }
        if ($filters['serie'] !== '') {
            $where[]  = 'r.numero_serie LIKE ?';
            $params[] = '%' . $filters['serie'] . '%';
        }

        // #P5 — filtros de validade agora usam a coluna DATE indexada
        match ($filters['validade']) {
            'valido'     => (function () use (&$where, &$params, $vcol, $hoje) {
                $where[]  = "$vcol >= ?";
                $params[] = $hoje;
            })(),
            '30dias'     => (function () use (&$where, &$params, $vcol, $hoje, $em30) {
                $where[]  = "$vcol >= ? AND $vcol <= ?";
                $params[] = $hoje;
                $params[] = $em30;
            })(),
            'vencido'    => (function () use (&$where, &$params, $vcol, $hoje) {
                $where[]  = "$vcol < ?";
                $params[] = $hoje;
            })(),
            'recentes30' => (function () use (&$where, &$params, $ha30, $hoje) {
                $where[]  = 'r.data_verificacao_efetiva >= ? AND r.data_verificacao_efetiva <= ?';
                $params[] = $ha30;
                $params[] = $hoje;
            })(),
            default      => null,
        };

        $wc = implode(' AND ', $where);

        // SELECT traz data_validade_date diretamente — sem DATE_FORMAT em runtime
        $dataQuery = "SELECT r.id, r.sigla_uf, r.uf, r.municipio,
                            NULLIF(TRIM(r.logradouro), '') AS logradouro,
                            r.nome_empresa,
                            r.data_ultima_verificacao, r.data_verificacao_efetiva,
                            r.data_validade, r.data_validade_date AS data_validade_iso,
                            r.situacao, r.tipo_medidor, r.link_waze
                     FROM radar_medidor r WHERE $wc ORDER BY r.sigla_uf, r.municipio";

        $countQuery = "SELECT COUNT(*) FROM radar_medidor r WHERE $wc";

        return $this->paginator->paginate($dataQuery, $countQuery, $params, $page, $perPage);
    }

    /**
     * #P4 — Substitui getStats() + totalMesclados do controller por uma única query.
     *
     * Antes: 3 roundtrips separados (Q3 COUNT paginação, Q5 SUM stats, Q6 COUNT mesclados)
     * Agora: 1 query com SUM(CASE WHEN ...) retorna tudo de uma vez.
     *
     * Cacheado por 5 minutos via QueryCacheProfile.
     *
     * @return array{total:int,aprovados:int,reprovados:int,vencidos:int,
     *               vencendo:int,estados:int,total_mesclados:int}|null
     */
    public function getStatsFull(): ?array
    {
        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $vcol = self::VALIDADE_COL;

        $result = $this->db->executeCacheQuery(
            "SELECT
                SUM(merged_into_id IS NULL)                               AS total,
                SUM(merged_into_id IS NULL AND situacao = 'APROVADO')     AS aprovados,
                SUM(merged_into_id IS NULL AND situacao = 'REPROVADO')    AS reprovados,
                SUM(merged_into_id IS NULL AND $vcol < ?)                 AS vencidos,
                SUM(merged_into_id IS NULL AND $vcol >= ? AND $vcol <= ?) AS vencendo,
                COUNT(DISTINCT IF(merged_into_id IS NULL, sigla_uf, NULL)) AS estados,
                SUM(merged_into_id IS NOT NULL)                           AS total_mesclados
             FROM radar_medidor r",
            [$hoje, $hoje, $em30],
            [],
            new QueryCacheProfile(self::STATS_CACHE_TTL, 'radar_stats_full')
        );

        return $result->fetchAssociative() ?: null;
    }

    /**
     * @deprecated Use getStatsFull() que também retorna total_mesclados.
     * Mantido para compatibilidade com código legado.
     */
    public function getStats(): array|false
    {
        return $this->getStatsFull();
    }

    // ── Cache HTTP nas páginas públicas ────────────────────────────────────────

    /**
     * #P3 — Aplica cabeçalhos Cache-Control em páginas públicas.
     */
    public function addPublicCacheHeaders(
        Response $response,
        int $maxAge  = 300,
        int $sMaxAge = 600
    ): void {
        $response->setPublic();
        $response->setMaxAge($maxAge);
        $response->setSharedMaxAge($sMaxAge);
        $response->headers->addCacheControlDirective('stale-while-revalidate', 60);
    }

    // ── #11 — Cache nas queries de filtros estáticos ───────────────────────────

    public function getUfsParaFiltro(?array $allowedUfs): array
    {
        $cacheKey = 'radar_ufs_' . ($allowedUfs === null ? 'all' : implode('_', $allowedUfs));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($allowedUfs): array {
            $item->expiresAfter(self::CACHE_TTL);

            if ($allowedUfs !== null) {
                $ph  = implode(',', array_fill(0, count($allowedUfs), '?'));
                $sql = "SELECT DISTINCT sigla_uf FROM radar_medidor
                        WHERE sigla_uf IS NOT NULL AND merged_into_id IS NULL
                        AND sigla_uf IN ($ph) ORDER BY sigla_uf";
                return array_column($this->db->fetchAllAssociative($sql, $allowedUfs), 'sigla_uf');
            }

            return array_column($this->db->fetchAllAssociative(
                'SELECT DISTINCT sigla_uf FROM radar_medidor
                 WHERE sigla_uf IS NOT NULL AND merged_into_id IS NULL ORDER BY sigla_uf'
            ), 'sigla_uf');
        });
    }

    public function getResultadosParaFiltro(): array
    {
        return $this->cache->get('radar_resultados', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);
            return array_column($this->db->fetchAllAssociative(
                'SELECT DISTINCT situacao FROM radar_medidor
                 WHERE situacao IS NOT NULL AND merged_into_id IS NULL ORDER BY situacao'
            ), 'situacao');
        });
    }

    public function getTiposParaFiltro(): array
    {
        return $this->cache->get('radar_tipos', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);
            return array_column($this->db->fetchAllAssociative(
                'SELECT DISTINCT tipo_medidor FROM radar_medidor
                 WHERE tipo_medidor IS NOT NULL AND merged_into_id IS NULL ORDER BY tipo_medidor'
            ), 'tipo_medidor');
        });
    }

    public function invalidarCacheFiltros(?array $allowedUfs = null): void
    {
        $this->cache->delete('radar_resultados');
        $this->cache->delete('radar_tipos');
        $ufsKey = 'radar_ufs_' . ($allowedUfs === null ? 'all' : implode('_', $allowedUfs));
        $this->cache->delete($ufsKey);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Escrita
    // ──────────────────────────────────────────────────────────────────────────

    public function saveEdit(int $id, array $postData, array $estadosMap, User $user): int
    {
        $radar   = $this->findOrFail($id);
        $agora   = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $userId  = $user->getId();

        $novaSigla = isset($postData['sigla_uf']) ? trim((string) $postData['sigla_uf']) : null;
        if ($novaSigla !== null && isset($estadosMap[$novaSigla])) {
            $postData['uf'] = $estadosMap[$novaSigla];
        }

        $alteracoes = [];

        foreach ($this->camposEditaveis as $campo => $rotulo) {
            if (!array_key_exists($campo, $postData)) {
                continue;
            }
            $novoValor   = trim((string) $postData[$campo]) ?: null;
            $valorAntigo = isset($radar[$campo]) ? ($radar[$campo] === '' ? null : $radar[$campo]) : null;

            if ($novoValor === $valorAntigo) {
                continue;
            }

            $this->db->insert('radar_edit_log', [
                'radar_medidor_id' => $id,
                'campo_alterado'   => $campo,
                'valor_anterior'   => $valorAntigo,
                'valor_novo'       => $novoValor,
                'editado_por'      => $userId,
                'editado_em'       => $agora,
            ]);

            $alteracoes[$campo] = $novoValor;
        }

        if (!empty($alteracoes)) {
            $alteracoes['updated_at']  = $agora;
            $alteracoes['inserted_by'] = $userId;
            $this->db->update('radar_medidor', $alteracoes, ['id' => $id]);
        }

        return count($alteracoes) > 0 ? count($alteracoes) - 2 : 0;
    }

    public function saveWazeLink(int $id, string $wazeLink, ?string $motivo, User $user): void
    {
        $errors = [];

        if ($wazeLink === '') {
            $errors['waze_link'] = 'O link é obrigatório.';
        } elseif (!filter_var($wazeLink, FILTER_VALIDATE_URL)) {
            $errors['waze_link'] = 'URL inválida.';
        } elseif (!str_contains($wazeLink, 'permanentHazards=')) {
            $errors['waze_link'] = 'O link deve conter permanentHazards=NÚMERO.';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(json_encode($errors));
        }

        preg_match('/permanentHazards=(\d+)/', $wazeLink, $m);
        $hazardId = (int) ($m[1] ?? 0);
        $agora    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->db->fetchAssociative(
            'SELECT * FROM radar_waze_link WHERE radar_medidor_id = ? ORDER BY id DESC LIMIT 1',
            [$id]
        ) ?: null;

        if ($existing) {
            $this->db->insert('radar_waze_link_log', [
                'radar_waze_link_id' => $existing['id'],
                'waze_link_anterior' => $existing['waze_link'],
                'waze_link_novo'     => $wazeLink,
                'hazard_id_anterior' => $existing['hazard_id'],
                'hazard_id_novo'     => $hazardId,
                'motivo'             => $motivo,
                'changed_by'         => $user->getId(),
                'changed_at'         => $agora,
            ]);

            $this->db->update('radar_waze_link', [
                'waze_link'  => $wazeLink,
                'hazard_id'  => $hazardId,
                'updated_by' => $user->getId(),
                'updated_at' => $agora,
            ], ['id' => $existing['id']]);
        } else {
            $this->db->insert('radar_waze_link', [
                'radar_medidor_id' => $id,
                'waze_link'        => $wazeLink,
                'hazard_id'        => $hazardId,
                'inserted_by'      => $user->getId(),
                'inserted_at'      => $agora,
            ]);
        }

        $this->db->update('radar_medidor', ['link_waze' => $wazeLink], ['id' => $id]);
    }

    public function getCamposEditaveis(): array
    {
        return $this->camposEditaveis;
    }

    public function getEditLog(int $id): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT el.*, u.email AS editado_por_email
             FROM radar_edit_log el
             LEFT JOIN user u ON u.id = el.editado_por
             WHERE el.radar_medidor_id = ?
             ORDER BY el.editado_em DESC LIMIT 30',
            [$id]
        );
    }

    public function getMescladosPaginated(int $page, int $perPage): array
    {
        $dataQuery = "SELECT r.id, r.sigla_uf, r.municipio, r.logradouro,
                            r.merged_into_id, r.merged_at, r.merged_by,
                            s.sigla_uf AS survivor_uf, s.municipio AS survivor_municipio
                     FROM radar_medidor r
                     INNER JOIN radar_medidor s ON s.id = r.merged_into_id
                     WHERE r.merged_into_id IS NOT NULL
                     ORDER BY r.merged_at DESC";

        $countQuery = 'SELECT COUNT(*) FROM radar_medidor WHERE merged_into_id IS NOT NULL';

        return $this->paginator->paginate($dataQuery, $countQuery, [], $page, $perPage);
    }
}
