<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Controller\Trait\AccessControlTrait;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/busca', name: 'busca_')]
final class BuscaController extends AbstractController
{
    use AccessControlTrait;

    private const LIMIT = 30;

    public function __construct(private readonly Connection $db) {}

    // -----------------------------------------------------------------------
    // Página principal com filtros
    // -----------------------------------------------------------------------
    #[Route('', name: 'index')]
    public function index(Request $req): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        $q    = trim((string) $req->query->get('q', ''));
        $tipo = trim((string) $req->query->get('tipo', ''));   // radar|posto|escola|''
        $uf   = trim((string) $req->query->get('uf', ''));

        $results = ['radares' => [], 'postos' => [], 'escolas' => []];

        if (strlen($q) >= 2) {
            $like = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q) . '%';

            /** @var User|null $user */
            $user       = $this->getUser();
            $allowedUfs = $user?->getUfsForQuery();

            if ($tipo === '' || $tipo === 'radar') {
                $results['radares'] = $this->searchRadares($like, $uf, $allowedUfs);
            }
            if ($tipo === '' || $tipo === 'posto') {
                $results['postos'] = $this->searchPostos($like, $uf, $allowedUfs);
            }
            if ($tipo === '' || $tipo === 'escola') {
                $results['escolas'] = $this->searchEscolas($like, $uf, $allowedUfs);
            }
        }

        $ufs = array_column(
            $this->db->fetchAllAssociative(
                "SELECT DISTINCT CONVERT(sigla_uf USING utf8mb4) COLLATE utf8mb4_unicode_ci AS uf
                 FROM radar_medidor WHERE sigla_uf IS NOT NULL
                 UNION
                 SELECT DISTINCT CONVERT(uf USING utf8mb4) COLLATE utf8mb4_unicode_ci
                 FROM fuel_reseller_raw WHERE uf IS NOT NULL
                 UNION
                 SELECT DISTINCT CONVERT(uf USING utf8mb4) COLLATE utf8mb4_unicode_ci
                 FROM escola_inep WHERE uf IS NOT NULL
                 ORDER BY uf"
            ),
            'uf'
        );

        return $this->render('busca/index.html.twig', array_merge($results, [
            'q'    => $q,
            'tipo' => $tipo,
            'uf'   => $uf,
            'ufs'  => $ufs,
        ]));
    }

    // -----------------------------------------------------------------------
    // Endpoint JSON para autocomplete do navbar (máx 8 resultados mistos)
    // -----------------------------------------------------------------------
    #[Route('/ac', name: 'autocomplete', methods: ['GET'])]
    public function autocomplete(Request $req): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        $q = trim((string) $req->query->get('q', ''));
        if (strlen($q) < 2) {
            return $this->json([]);
        }

        $like = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q) . '%';

        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $items = [];

        // 3 radares
        foreach ($this->searchRadares($like, '', $allowedUfs, 3) as $r) {
            $items[] = [
                'tipo'  => 'radar',
                'label' => '[' . $r['sigla_uf'] . '] ' . ($r['logradouro'] ?: $r['municipio']),
                'url'   => '/radares/' . $r['id'],
            ];
        }
        // 3 postos
        foreach ($this->searchPostos($like, '', $allowedUfs, 3) as $p) {
            $items[] = [
                'tipo'  => 'posto',
                'label' => '[' . $p['uf'] . '] ' . ($p['nome_fantasia'] ?: $p['razao_social']),
                'url'   => '/postos/' . $p['id'],
            ];
        }
        // 2 escolas
        foreach ($this->searchEscolas($like, '', $allowedUfs, 2) as $e) {
            $items[] = [
                'tipo'  => 'escola',
                'label' => '[' . $e['uf'] . '] ' . $e['escola'],
                'url'   => '/escolas/' . $e['id'],
            ];
        }

        return $this->json($items);
    }

    // -----------------------------------------------------------------------
    // Helpers privados
    // -----------------------------------------------------------------------
    private function searchRadares(string $like, string $uf, ?array $allowedUfs, int $limit = self::LIMIT): array
    {
        [$where, $params] = $this->baseUfWhere('sigla_uf', $uf, $allowedUfs);
        $where[] = '(municipio LIKE ? OR logradouro LIKE ? OR nome_empresa LIKE ?)';
        array_push($params, $like, $like, $like);

        return $this->db->fetchAllAssociative(
            'SELECT id, sigla_uf, municipio, logradouro, situacao, tipo_medidor
             FROM radar_medidor
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY sigla_uf, municipio LIMIT ' . $limit,
            $params
        );
    }

    private function searchPostos(string $like, string $uf, ?array $allowedUfs, int $limit = self::LIMIT): array
    {
        [$where, $params] = $this->baseUfWhere('uf', $uf, $allowedUfs);
        $where[] = '(razao_social LIKE ? OR nome_fantasia LIKE ? OR municipio LIKE ?)';
        array_push($params, $like, $like, $like);

        return $this->db->fetchAllAssociative(
            'SELECT id, uf, municipio, razao_social, nome_fantasia, bandeira
             FROM fuel_reseller_raw
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY uf, municipio LIMIT ' . $limit,
            $params
        );
    }

    private function searchEscolas(string $like, string $uf, ?array $allowedUfs, int $limit = self::LIMIT): array
    {
        [$where, $params] = $this->baseUfWhere('uf', $uf, $allowedUfs);
        $where[] = '(escola LIKE ? OR codigo_inep LIKE ? OR municipio LIKE ?)';
        array_push($params, $like, $like, $like);

        return $this->db->fetchAllAssociative(
            'SELECT id, uf, municipio, escola, codigo_inep, dependencia_administrativa
             FROM escola_inep
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY uf, escola LIMIT ' . $limit,
            $params
        );
    }

    /**
     * Monta cláusulas WHERE base de UF: filtro manual + restrição do usuário.
     * @return array{0: string[], 1: mixed[]}
     */
    private function baseUfWhere(string $col, string $uf, ?array $allowedUfs): array
    {
        $where  = ['1=1'];
        $params = [];

        if ($uf !== '') {
            $where[]  = "$col = ?";
            $params[] = $uf;
        }

        if ($allowedUfs !== null) {
            if (count($allowedUfs) === 0) {
                $where[] = '1=0';
            } else {
                $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
                $where[] = "$col IN ($ph)";
                foreach ($allowedUfs as $u) { $params[] = $u; }
            }
        }

        return [$where, $params];
    }
}
