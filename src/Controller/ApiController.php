<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API JSON pública (somente leitura).
 * Todos os endpoints retornam JSON; não requerem autenticação.
 * Rate-limiting deve ser configurado no nginx/proxy externo se necessário.
 */
#[Route('/api/v1', name: 'api_v1_')]
final class ApiController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly Connection $db,
    ) {}

    // ───────────────────────────────────────────────────────────────
    // RADARES
    // ───────────────────────────────────────────────────────────────

    /** GET /api/v1/radares */
    #[Route('/radares', name: 'radares', methods: ['GET'])]
    public function radares(Request $req): JsonResponse
    {
        $page      = max(1, (int) $req->query->get('page', 1));
        $perPage   = min((int) ($req->query->get('per_page', self::PER_PAGE)), 200);
        $offset    = ($page - 1) * $perPage;
        $uf        = strtoupper(trim((string) $req->query->get('uf', '')));
        $resultado = trim((string) $req->query->get('resultado', ''));
        $municipio = trim((string) $req->query->get('municipio', ''));
        $comWaze   = $req->query->has('com_waze');
        $semWaze   = $req->query->has('sem_waze');

        [$where, $params] = $this->radarWhere($uf, $resultado, $municipio, $comWaze, $semWaze);
        $wc = $where ? "WHERE $where" : '';
        $join = ($comWaze || $semWaze) ? 'LEFT JOIN radar_waze_link rwl ON rwl.radar_medidor_id = rm.id' : '';

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM radar_medidor rm $join $wc", $params);
        $lim   = (int) $perPage;
        $off   = (int) $offset;

        $rows = $this->db->fetchAllAssociative(
            "SELECT rm.id, rm.sigla_uf, rm.municipio, rm.local_verificacao,
                    rm.ultimo_resultado, rm.tipo_medidor, rm.proprietario_nome,
                    rm.data_validade, rm.data_ultima_verificacao,
                    rwl.waze_link, rwl.permanent_hazard_id
             FROM radar_medidor rm $join
             $wc
             ORDER BY rm.sigla_uf, rm.municipio
             LIMIT $lim OFFSET $off",
            $params
        );

        return $this->json([
            'data'       => $rows,
            'pagination' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /** GET /api/v1/radares/{id} */
    #[Route('/radares/{id}', name: 'radar_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function radarShow(int $id): JsonResponse
    {
        $row = $this->db->fetchAssociative(
            'SELECT rm.*, rwl.waze_link, rwl.permanent_hazard_id
             FROM radar_medidor rm
             LEFT JOIN radar_waze_link rwl ON rwl.radar_medidor_id = rm.id
             WHERE rm.id = ?',
            [$id]
        );

        if (!$row) {
            return $this->json(['error' => 'Radar não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $faixas = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_faixa WHERE radar_medidor_id = ? ORDER BY numero_faixa', [$id]
        );

        return $this->json(['data' => $row, 'faixas' => $faixas]);
    }

    /** GET /api/v1/radares/stats */
    #[Route('/radares/stats', name: 'radar_stats', methods: ['GET'])]
    public function radarStats(): JsonResponse
    {
        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $dv   = "STR_TO_DATE(data_validade, '%d/%m/%Y')";

        $stats = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    SUM(ultimo_resultado='APROVADO')  AS aprovados,
                    SUM(ultimo_resultado='REPROVADO') AS reprovados,
                    SUM(data_validade IS NOT NULL AND $dv < ?)            AS vencidos,
                    SUM(data_validade IS NOT NULL AND $dv BETWEEN ? AND ?) AS vencendo,
                    COUNT(DISTINCT sigla_uf) AS estados
             FROM radar_medidor",
            [$hoje, $hoje, $em30]
        );

        $comWaze = (int) $this->db->fetchOne('SELECT COUNT(*) FROM radar_waze_link');
        $stats['com_waze'] = $comWaze;
        $stats['sem_waze'] = (int)$stats['total'] - $comWaze;

        return $this->json(['data' => $stats]);
    }

    // ───────────────────────────────────────────────────────────────
    // POSTOS
    // ───────────────────────────────────────────────────────────────

    /** GET /api/v1/postos */
    #[Route('/postos', name: 'postos', methods: ['GET'])]
    public function postos(Request $req): JsonResponse
    {
        $page    = max(1, (int) $req->query->get('page', 1));
        $perPage = min((int) ($req->query->get('per_page', self::PER_PAGE)), 200);
        $offset  = ($page - 1) * $perPage;
        $uf      = strtoupper(trim((string) $req->query->get('uf', '')));
        $mun     = trim((string) $req->query->get('municipio', ''));
        $semWaze = $req->query->has('sem_waze');
        $comWaze = $req->query->has('com_waze');

        $parts  = [];
        $params = [];

        if ($uf !== '') { $parts[] = 'frr.uf = ?'; $params[] = $uf; }
        if ($mun !== '') { $parts[] = 'frr.municipio LIKE ?'; $params[] = "%$mun%"; }
        if ($semWaze) { $parts[] = 'pwl.posto_id IS NULL'; }
        if ($comWaze) { $parts[] = 'pwl.posto_id IS NOT NULL'; }

        $wc   = $parts ? 'WHERE ' . implode(' AND ', $parts) : '';
        $join = 'LEFT JOIN posto_waze_link pwl ON pwl.posto_id = frr.id';

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM fuel_reseller_raw frr $join $wc", $params);
        $lim   = (int) $perPage;
        $off   = (int) $offset;

        $rows = $this->db->fetchAllAssociative(
            "SELECT frr.id, frr.cnpj, frr.razao_social, frr.nome_fantasia,
                    frr.bandeira, frr.municipio, frr.uf, frr.endereco,
                    frr.status, pwl.waze_link, pwl.permanent_hazard_id
             FROM fuel_reseller_raw frr $join
             $wc
             ORDER BY frr.uf, frr.municipio
             LIMIT $lim OFFSET $off",
            $params
        );

        return $this->json([
            'data'       => $rows,
            'pagination' => compact('page', 'perPage', 'total') + ['pages' => (int) ceil($total / $perPage)],
        ]);
    }

    // ───────────────────────────────────────────────────────────────
    // BUSCA GLOBAL (autocomplete)
    // ───────────────────────────────────────────────────────────────

    /** GET /api/v1/busca?q=texto — retorna até 5 radares + 5 postos */
    #[Route('/busca', name: 'busca', methods: ['GET'])]
    public function busca(Request $req): JsonResponse
    {
        $q = trim((string) $req->query->get('q', ''));
        if (strlen($q) < 2) {
            return $this->json(['radares' => [], 'postos' => []]);
        }

        $like = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q) . '%';

        $radares = $this->db->fetchAllAssociative(
            "SELECT id, sigla_uf AS uf, municipio, local_verificacao AS local, ultimo_resultado
             FROM radar_medidor
             WHERE municipio LIKE ? OR local_verificacao LIKE ?
             LIMIT 5",
            [$like, $like]
        );

        $postos = $this->db->fetchAllAssociative(
            "SELECT id, uf, municipio, razao_social AS nome, nome_fantasia
             FROM fuel_reseller_raw
             WHERE razao_social LIKE ? OR nome_fantasia LIKE ? OR municipio LIKE ?
             LIMIT 5",
            [$like, $like, $like]
        );

        return $this->json(['radares' => $radares, 'postos' => $postos]);
    }

    // ───────────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────────

    private function radarWhere(string $uf, string $resultado, string $municipio, bool $comWaze, bool $semWaze): array
    {
        $parts  = [];
        $params = [];

        if ($uf !== '')        { $parts[] = 'rm.sigla_uf = ?';       $params[] = $uf; }
        if ($resultado !== '') { $parts[] = 'rm.ultimo_resultado = ?'; $params[] = $resultado; }
        if ($municipio !== '') { $parts[] = 'rm.municipio LIKE ?';   $params[] = "%$municipio%"; }
        if ($semWaze)          { $parts[] = 'rwl.id IS NULL'; }
        if ($comWaze)          { $parts[] = 'rwl.id IS NOT NULL'; }

        return [implode(' AND ', $parts), $params];
    }
}
