<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Encapsula toda a lógica de negócio, queries SQL e regras de validação
 * relacionadas a radares, desafogando o RadarController.
 */
final class RadarService
{
    private const VALIDADE_ISO_EXPR = "STR_TO_DATE(r.data_validade, '%d/%m/%Y')";

    /** @var array<string,string> */
    private array $camposEditaveis;

    public function __construct(
        private readonly Connection       $db,
        private readonly PaginatorService $paginator,
        string $projectDir
    ) {
        $this->camposEditaveis = require $projectDir . '/config/radar_campos.php';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Leitura
    // ──────────────────────────────────────────────────────────────────────────

    public function findOrFail(int $id): array
    {
        $viso  = self::VALIDADE_ISO_EXPR;
        $radar = $this->db->fetchAssociative(
            "SELECT r.*, DATE_FORMAT($viso, '%Y-%m-%d') AS data_validade_iso
             FROM radar_medidor r WHERE r.id = ?",
            [$id]
        );

        if (!$radar) {
            throw new \RuntimeException("Radar #{$id} não encontrado.", 404);
        }

        return $radar;
    }

    /**
     * Monta todos os dados necessários para renderizar radar/show.html.twig.
     * Usado tanto pelo show() quanto pelo wazeSave() em caso de erro de validação.
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

        $absorbedRadares = $this->db->fetchAllAssociative(
            'SELECT id, sigla_uf, municipio, logradouro, merged_at, merged_by
             FROM radar_medidor WHERE merged_into_id = ? ORDER BY merged_at DESC',
            [$id]
        );

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

        $historico = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_historico WHERE radar_medidor_id = ? ORDER BY data_laudo DESC LIMIT 10',
            [$id]
        );

        $faixas = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_faixa WHERE radar_medidor_id = ? ORDER BY numero_faixa',
            [$id]
        );

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
     * @param  array{uf:string,municipio:string,resultado:string,tipo:string,validade:string,serie:string} $filters
     * @param  int   $page
     * @param  int   $perPage
     * @param  array|null $allowedUfs   null = sem restrição de UF
     * @return array{rows:list<array>,total:int,pages:int,page:int,offset:int}
     */
    public function findPaginated(
        array  $filters,
        int    $page,
        int    $perPage,
        ?array $allowedUfs = null
    ): array {
        $viso   = self::VALIDADE_ISO_EXPR;
        $hoje   = (new \DateTimeImmutable())->format('Y-m-d');
        $em30   = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $ha30   = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

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

        // #2 — datas agora passadas como parâmetros, não interpoladas na string SQL
        match ($filters['validade']) {
            'valido'     => (function () use (&$where, &$params, $viso, $hoje) {
                $where[]  = "$viso >= ?";
                $params[] = $hoje;
            })(),
            '30dias'     => (function () use (&$where, &$params, $viso, $hoje, $em30) {
                $where[]  = "$viso >= ? AND $viso <= ?";
                $params[] = $hoje;
                $params[] = $em30;
            })(),
            'vencido'    => (function () use (&$where, &$params, $viso, $hoje) {
                $where[]  = "$viso < ?";
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

        $dataQuery = "SELECT r.id, r.sigla_uf, r.uf, r.municipio,
                            NULLIF(TRIM(r.logradouro), '') AS logradouro,
                            r.nome_empresa,
                            r.data_ultima_verificacao, r.data_verificacao_efetiva,
                            r.data_validade, r.situacao, r.tipo_medidor, r.link_waze,
                            DATE_FORMAT($viso, '%Y-%m-%d') AS data_validade_iso
                     FROM radar_medidor r WHERE $wc ORDER BY r.sigla_uf, r.municipio";

        $countQuery = "SELECT COUNT(*) FROM radar_medidor r WHERE $wc";

        return $this->paginator->paginate($dataQuery, $countQuery, $params, $page, $perPage);
    }

    /**
     * Retorna estatísticas globais (usado quando não há filtros ativos).
     * #2 — datas parametrizadas.
     */
    public function getStats(): array|false
    {
        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $viso = self::VALIDADE_ISO_EXPR;

        return $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    SUM(situacao = 'APROVADO') AS aprovados,
                    SUM(situacao = 'REPROVADO') AS reprovados,
                    SUM($viso < ?) AS vencidos,
                    SUM($viso >= ? AND $viso <= ?) AS vencendo,
                    COUNT(DISTINCT sigla_uf) AS estados
             FROM radar_medidor WHERE merged_into_id IS NULL",
            [$hoje, $hoje, $em30]
        ) ?: null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Escrita
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Persiste as alterações de campos editáveis e grava o log de auditoria.
     *
     * @param  int    $id
     * @param  array  $postData    Dados brutos do POST (Request::request->all())
     * @param  array  $estadosMap  ['UF' => 'Nome do estado']
     * @param  User   $user
     * @return int Número de campos efetivamente alterados
     */
    public function saveEdit(int $id, array $postData, array $estadosMap, User $user): int
    {
        $radar = $this->findOrFail($id);
        $agora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $userEmail = $user->getUserIdentifier();

        // Se a UF mudou, força o nome oficial ignorando o que vier no POST
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
                'editado_por'      => $userEmail,
                'editado_em'       => $agora,
            ]);

            $alteracoes[$campo] = $novoValor;
        }

        if (!empty($alteracoes)) {
            $alteracoes['updated_at']  = $agora;
            $alteracoes['inserted_by'] = $userEmail;
            $this->db->update('radar_medidor', $alteracoes, ['id' => $id]);
        }

        // Retorna contagem de campos reais alterados (descontando os metadados)
        return count($alteracoes) > 0 ? count($alteracoes) - 2 : 0;
    }

    /**
     * Valida e persiste o link Waze. Lança \InvalidArgumentException com a
     * lista de erros se a validação falhar.
     *
     * @throws \InvalidArgumentException
     */
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
            'SELECT * FROM radar_waze_link WHERE radar_medidor_id = ?', [$id]
        );

        if ($existing) {
            $this->db->insert('radar_waze_link_log', [
                'radar_waze_link_id' => $existing['id'],
                'campo_alterado'     => 'waze_link',
                'valor_anterior'     => $existing['waze_link'],
                'valor_novo'         => $wazeLink,
                'changed_by'         => $user->getId(),
                'changed_at'         => $agora,
            ]);
            $this->db->update('radar_waze_link', [
                'waze_link'           => $wazeLink,
                'permanent_hazard_id' => $hazardId,
                'updated_by'          => $user->getId(),
                'updated_at'          => $agora,
                'observacao'          => $motivo,
            ], ['radar_medidor_id' => $id]);
        } else {
            $this->db->insert('radar_waze_link', [
                'radar_medidor_id'    => $id,
                'waze_link'           => $wazeLink,
                'permanent_hazard_id' => $hazardId,
                'inserted_by'         => $user->getId(),
                'inserted_at'         => $agora,
                'observacao'          => $motivo,
            ]);
        }

        $this->db->update('radar_medidor', ['link_waze' => $wazeLink, 'updated_at' => $agora], ['id' => $id]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers públicos
    // ──────────────────────────────────────────────────────────────────────────

    /** @return array<string,string> */
    public function getCamposEditaveis(): array
    {
        return $this->camposEditaveis;
    }

    public function getEditLog(int $id, int $limit = 30): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM radar_edit_log WHERE radar_medidor_id = ? ORDER BY editado_em DESC LIMIT ' . $limit,
            [$id]
        );
    }

    public function getMescladosPaginated(int $page, int $perPage): array
    {
        $dataQuery = "SELECT r.id, r.sigla_uf, r.municipio,
                            NULLIF(TRIM(r.logradouro),'') AS logradouro,
                            r.tipo_medidor, r.situacao, r.merged_into_id, r.merged_at, r.merged_by,
                            s.municipio AS survivor_municipio,
                            NULLIF(TRIM(s.logradouro),'') AS survivor_logradouro
                     FROM radar_medidor r
                     JOIN radar_medidor s ON s.id = r.merged_into_id
                     WHERE r.merged_into_id IS NOT NULL
                     ORDER BY r.merged_at DESC, r.id DESC";

        $countQuery = 'SELECT COUNT(*) FROM radar_medidor WHERE merged_into_id IS NOT NULL';

        return $this->paginator->paginate($dataQuery, $countQuery, [], $page, $perPage);
    }
}
