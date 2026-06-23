<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PostoStatsService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/exportar', name: 'export_')]
final class ExportController extends AbstractController
{
    public function __construct(
        private readonly PostoStatsService $stats,
        private readonly Connection $db,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    //  POSTOS
    // ─────────────────────────────────────────────────────────────────────
    #[Route('/postos.csv', name: 'postos_csv')]
    public function postosCsv(Request $req): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $filters = [
            'uf'        => trim((string) $req->query->get('uf', '')),
            'municipio' => trim((string) $req->query->get('municipio', '')),
            'bandeira'  => trim((string) $req->query->get('bandeira', '')),
            'sem_waze'  => $req->query->getBoolean('sem_waze'),
            'venue_id'  => trim((string) $req->query->get('venue_id', '')),
            'status'    => trim((string) $req->query->get('status', '')),
        ];

        $csv      = $this->stats->exportCsv($allowedUfs, $filters);
        $filename = 'postos_' . date('Ymd_His') . '.csv';

        $response = new Response("\xEF\xBB\xBF" . $csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"$filename\"");

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  RADARES – CSV
    // ─────────────────────────────────────────────────────────────────────
    #[Route('/radares.csv', name: 'radares_csv')]
    public function radaresCsv(Request $req): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        [$uf, $municipio, $resultado, $tipo, $validade, $serie, $semWaze] = $this->extractRadarFilters($req);

        [$where, $params] = $this->buildRadarWhere(
            $uf, $municipio, $resultado, $tipo, $validade, $serie, $semWaze, $allowedUfs
        );

        $rows = $this->fetchRadarRows($where, $params, $serie);

        $headers = [
            'ID', 'UF', 'Estado', 'Município', 'Local',
            'Tipo Medidor', 'Resultado', 'Última Verificação',
            'Data Verificação Efetiva', 'Validade', 'Proprietário',
            'Tem Waze', 'Link Waze',
        ];

        $lines   = [];
        $lines[] = implode(';', array_map([$this, 'csvCell'], $headers));

        foreach ($rows as $r) {
            $lines[] = implode(';', array_map([$this, 'csvCell'], [
                $r['id'], $r['sigla_uf'], $r['estado'], $r['municipio'],
                $r['local_verificacao'], $r['tipo_medidor'], $r['ultimo_resultado'],
                $r['data_ultima_verificacao'], $r['data_verificacao_efetiva'],
                $r['data_validade'], $r['proprietario_nome'],
                $r['tem_waze'], $r['waze_link'] ?? '',
            ]));
        }

        $csv      = implode("\n", $lines);
        $filename = 'radares_' . date('Ymd_His') . '.csv';

        $response = new Response("\xEF\xBB\xBF" . $csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"$filename\"");

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  RADARES – GeoJSON  (#8)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Exporta radares como GeoJSON para importação direta no Waze Map Editor.
     * Apenas radares que possuam latitude/longitude são incluídos.
     */
    #[Route('/radares.geojson', name: 'radares_geojson')]
    public function radaresGeoJson(Request $req): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        [$uf, $municipio, $resultado, $tipo, $validade, $serie, $semWaze] = $this->extractRadarFilters($req);

        [$where, $params] = $this->buildRadarWhere(
            $uf, $municipio, $resultado, $tipo, $validade, $serie, $semWaze, $allowedUfs
        );

        // Só radares com coordenadas
        $where[] = 'rm.latitude IS NOT NULL AND rm.longitude IS NOT NULL';

        $baseFrom    = $serie !== '' ? 'LEFT JOIN radar_faixa rf ON rf.radar_medidor_id = rm.id' : '';
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = $this->db->fetchAllAssociative(
            "SELECT DISTINCT
                    rm.id, rm.sigla_uf, rm.municipio, rm.local_verificacao,
                    rm.tipo_medidor, rm.ultimo_resultado, rm.data_validade,
                    rm.proprietario_nome, rm.latitude, rm.longitude,
                    CASE WHEN rwl.id IS NOT NULL THEN 1 ELSE 0 END AS tem_waze,
                    rwl.waze_link
             FROM radar_medidor rm
             $baseFrom
             LEFT JOIN radar_waze_link rwl ON rwl.radar_medidor_id = rm.id
             $whereClause
             ORDER BY rm.sigla_uf, rm.municipio",
            $params
        );

        $features = [];
        foreach ($rows as $r) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type'        => 'Point',
                    'coordinates' => [(float) $r['longitude'], (float) $r['latitude']],
                ],
                'properties' => [
                    'id'               => (int) $r['id'],
                    'uf'               => $r['sigla_uf'],
                    'municipio'        => $r['municipio'],
                    'local'            => $r['local_verificacao'],
                    'tipo_medidor'     => $r['tipo_medidor'],
                    'resultado'        => $r['ultimo_resultado'],
                    'data_validade'    => $r['data_validade'],
                    'proprietario'     => $r['proprietario_nome'],
                    'tem_waze'         => (bool) $r['tem_waze'],
                    'waze_link'        => $r['waze_link'] ?? null,
                ],
            ];
        }

        $geojson = json_encode([
            'type'     => 'FeatureCollection',
            'features' => $features,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $filename = 'radares_' . date('Ymd_His') . '.geojson';

        $response = new Response($geojson);
        $response->headers->set('Content-Type', 'application/geo+json; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"$filename\"");

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Helpers privados
    // ─────────────────────────────────────────────────────────────────────

    /** Extrai e normaliza filtros de radar do Request. */
    private function extractRadarFilters(Request $req): array
    {
        return [
            strtoupper(trim((string) $req->query->get('uf', ''))),
            trim((string) $req->query->get('municipio', '')),
            trim((string) $req->query->get('resultado', '')),
            trim((string) $req->query->get('tipo', '')),
            trim((string) $req->query->get('validade', '')),
            trim((string) $req->query->get('serie', '')),
            $req->query->getBoolean('sem_waze'),
        ];
    }

    private function fetchRadarRows(array $where, array $params, string $serie): array
    {
        $baseFrom    = $serie !== '' ? 'LEFT JOIN radar_faixa rf ON rf.radar_medidor_id = rm.id' : '';
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return $this->db->fetchAllAssociative(
            "SELECT DISTINCT
                    rm.id, rm.sigla_uf, rm.estado, rm.municipio,
                    rm.local_verificacao, rm.tipo_medidor, rm.ultimo_resultado,
                    rm.data_ultima_verificacao, rm.data_verificacao_efetiva,
                    rm.data_validade, rm.proprietario_nome,
                    CASE WHEN rwl.id IS NOT NULL THEN 'Sim' ELSE 'Não' END AS tem_waze,
                    rwl.waze_link
             FROM radar_medidor rm
             $baseFrom
             LEFT JOIN radar_waze_link rwl ON rwl.radar_medidor_id = rm.id
             $whereClause
             ORDER BY rm.sigla_uf, rm.municipio, rm.local_verificacao",
            $params
        );
    }

    private function csvCell(mixed $v): string
    {
        $v = (string) ($v ?? '');
        if (str_contains($v, ';') || str_contains($v, '"') || str_contains($v, "\n")) {
            return '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }

    private function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    private function buildRadarWhere(
        string $uf,
        string $municipio,
        string $resultado,
        string $tipo,
        string $validade,
        string $serie,
        bool   $semWaze,
        ?array $allowedUfs,
    ): array {
        $parts  = [];
        $params = [];

        if ($allowedUfs !== null) {
            if (count($allowedUfs) === 0) {
                $parts[] = '1=0';
            } else {
                $ph      = implode(',', array_fill(0, count($allowedUfs), '?'));
                $parts[] = "rm.sigla_uf IN ($ph)";
                foreach ($allowedUfs as $u) { $params[] = $u; }
            }
        }

        if ($uf !== '') { $parts[] = 'rm.sigla_uf = ?'; $params[] = $uf; }
        if ($municipio !== '') { $parts[] = 'rm.municipio LIKE ?'; $params[] = '%' . $this->escapeLike($municipio) . '%'; }
        if ($resultado !== '') { $parts[] = 'rm.ultimo_resultado = ?'; $params[] = $resultado; }
        if ($tipo !== '') { $parts[] = 'rm.tipo_medidor = ?'; $params[] = $tipo; }

        if ($serie !== '') {
            $esc      = $this->escapeLike($serie);
            $parts[]  = '(rf.numero_serie LIKE ? OR rf.numero_inmetro LIKE ?)';
            $params[] = "%$esc%";
            $params[] = "%$esc%";
        }
        if ($semWaze) {
            $parts[] = '(SELECT COUNT(*) FROM radar_waze_link rwl2 WHERE rwl2.radar_medidor_id = rm.id) = 0';
        }

        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $dv   = "STR_TO_DATE(rm.data_validade, '%d/%m/%Y')";

        if ($validade === 'vencido') {
            $parts[]  = "$dv < ?";
            $params[] = $hoje;
        } elseif ($validade === 'valido') {
            $parts[]  = "$dv >= ?";
            $params[] = $hoje;
        } elseif ($validade === '30dias') {
            $parts[]  = "$dv >= ? AND $dv <= ?";
            $params[] = $hoje;
            $params[] = $em30;
        }

        return [$parts, $params];
    }
}
