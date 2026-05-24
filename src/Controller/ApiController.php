<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API JSON interna — usada pela busca global da navbar e por integrações externas.
 *
 * Todas as rotas são somente-leitura (GET).
 * Rotas públicas: /api/busca  (autocomplete)
 * Rotas protegidas: /api/radares  /api/postos
 */
#[Route('/api', name: 'api_')]
final class ApiController extends AbstractController
{
    private const LIMIT_AUTOCOMPLETE = 8;
    private const LIMIT_LIST        = 100;

    public function __construct(
        private readonly Connection $db,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    //  Busca global para autocomplete da navbar
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/busca?q=texto
     *
     * Retorna até 8 resultados combinados de radares e postos.
     * Sem autenticação obrigatória — resultado filtrado pela UF do usuário se logado.
     */
    #[Route('/busca', name: 'busca', methods: ['GET'])]
    public function busca(Request $req): JsonResponse
    {
        $q = trim((string) $req->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->json([]);
        }

        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $like = '%' . $this->escapeLike($q) . '%';
        $lim  = (int) self::LIMIT_AUTOCOMPLETE;

        // ── Radares ────────────────────────────────────────────────────
        [$ufWhere, $ufParams] = $this->ufFilter($allowedUfs, 'sigla_uf');
        $radarWhere = $ufWhere ? "($ufWhere) AND" : '';

        $radares = $this->db->fetchAllAssociative(
            "SELECT id, sigla_uf AS uf, municipio, local_verificacao AS local,
                    ultimo_resultado AS resultado, 'radar' AS tipo
             FROM radar_medidor
             WHERE $radarWhere (municipio LIKE ? OR local_verificacao LIKE ?)
             ORDER BY sigla_uf, municipio
             LIMIT $lim",
            array_merge($ufParams, [$like, $like])
        );

        // ── Postos ─────────────────────────────────────────────────────
        [$ufWhere2, $ufParams2] = $this->ufFilter($allowedUfs, 'uf');
        $postoWhere = $ufWhere2 ? "($ufWhere2) AND" : '';

        $postos = $this->db->fetchAllAssociative(
            "SELECT id, uf, municipio, razao_social AS local,
                    bandeira AS resultado, 'posto' AS tipo
             FROM fuel_reseller_raw
             WHERE $postoWhere (razao_social LIKE ? OR municipio LIKE ?)
             ORDER BY uf, municipio
             LIMIT $lim",
            array_merge($ufParams2, [$like, $like])
        );

        $results = array_merge(
            array_map(fn($r) => [
                'tipo'     => 'radar',
                'id'       => (int) $r['id'],
                'label'    => "{$r['uf']} — {$r['municipio']}: {$r['local']}",
                'badge'    => $r['resultado'],
                'url'      => '/radares/' . $r['id'],
            ], $radares),
            array_map(fn($r) => [
                'tipo'     => 'posto',
                'id'       => (int) $r['id'],
                'label'    => "{$r['uf']} — {$r['municipio']}: {$r['local']}",
                'badge'    => $r['resultado'],
                'url'      => '/postos/' . $r['id'],
            ], $postos)
        );

        // ordena por label e limita ao total
        usort($results, fn($a, $b) => strcmp($a['label'], $b['label']));
        $results = array_slice($results, 0, (int) self::LIMIT_AUTOCOMPLETE * 2);

        return $this->json($results, Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  API pública de Radares
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/radares?uf=SP&resultado=APROVADO&page=1
     *
     * Requer autenticação.
     */
    #[Route('/radares', name: 'radares', methods: ['GET'])]
    public function radares(Request $req): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user       = $this->getUser();
        $allowedUfs = $user->getUfsForQuery();

        $uf        = strtoupper(trim((string) $req->query->get('uf', '')));
        $municipio = trim((string) $req->query->get('municipio', ''));
        $resultado = trim((string) $req->query->get('resultado', ''));
        $page      = max(1, (int) $req->query->get('page', 1));
        $limit     = min((int) $req->query->get('limit', 50), self::LIMIT_LIST);
        $offset    = ($page - 1) * $limit;

        $parts  = [];
        $params = [];

        if ($allowedUfs !== null) {
            if (count($allowedUfs) === 0) { return $this->json(['data' => [], 'total' => 0]); }
            $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
            $parts[] = "sigla_uf IN ($ph)";
            foreach ($allowedUfs as $u) { $params[] = $u; }
        }
        if ($uf !== '') { $parts[] = 'sigla_uf = ?'; $params[] = $uf; }
        if ($municipio !== '') { $parts[] = 'municipio LIKE ?'; $params[] = '%' . $this->escapeLike($municipio) . '%'; }
        if ($resultado !== '') { $parts[] = 'ultimo_resultado = ?'; $params[] = $resultado; }

        $wc    = $parts ? 'WHERE ' . implode(' AND ', $parts) : '';
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM radar_medidor $wc", $params);

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, sigla_uf, municipio, local_verificacao, tipo_medidor,
                    ultimo_resultado, data_validade, proprietario_nome
             FROM radar_medidor $wc
             ORDER BY sigla_uf, municipio
             LIMIT $limit OFFSET $offset",
            $params
        );

        return $this->json([
            'data'  => $rows,
            'total' => $total,
            'page'  => $page,
            'pages' => (int) ceil($total / $limit),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  API pública de Postos
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/postos?uf=MG&municipio=Belo+Horizonte&page=1
     *
     * Requer autenticação.
     */
    #[Route('/postos', name: 'postos', methods: ['GET'])]
    public function postos(Request $req): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User $user */
        $user       = $this->getUser();
        $allowedUfs = $user->getUfsForQuery();

        $uf        = strtoupper(trim((string) $req->query->get('uf', '')));
        $municipio = trim((string) $req->query->get('municipio', ''));
        $bandeira  = trim((string) $req->query->get('bandeira', ''));
        $semWaze   = $req->query->getBoolean('sem_waze');
        $page      = max(1, (int) $req->query->get('page', 1));
        $limit     = min((int) $req->query->get('limit', 50), self::LIMIT_LIST);
        $offset    = ($page - 1) * $limit;

        $parts  = [];
        $params = [];

        if ($allowedUfs !== null) {
            if (count($allowedUfs) === 0) { return $this->json(['data' => [], 'total' => 0]); }
            $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
            $parts[] = "uf IN ($ph)";
            foreach ($allowedUfs as $u) { $params[] = $u; }
        }
        if ($uf !== '')       { $parts[] = 'uf = ?';          $params[] = $uf; }
        if ($municipio !== '') { $parts[] = 'municipio LIKE ?'; $params[] = '%' . $this->escapeLike($municipio) . '%'; }
        if ($bandeira !== '')  { $parts[] = 'bandeira = ?';    $params[] = $bandeira; }
        if ($semWaze) { $parts[] = '(SELECT COUNT(*) FROM posto_waze_link pwl WHERE pwl.posto_id = fuel_reseller_raw.id) = 0'; }

        $wc    = $parts ? 'WHERE ' . implode(' AND ', $parts) : '';
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM fuel_reseller_raw $wc", $params);

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, cnpj, razao_social, nome_fantasia, municipio, uf, bandeira, endereco
             FROM fuel_reseller_raw $wc
             ORDER BY uf, municipio, razao_social
             LIMIT $limit OFFSET $offset",
            $params
        );

        return $this->json([
            'data'  => $rows,
            'total' => $total,
            'page'  => $page,
            'pages' => (int) ceil($total / $limit),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    private function ufFilter(?array $allowedUfs, string $col): array
    {
        if ($allowedUfs === null) { return ['', []]; }
        if (count($allowedUfs) === 0) { return ['1=0', []]; }
        $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
        return ["{$col} IN ($ph)", $allowedUfs];
    }
}
