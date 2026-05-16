<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/radares', name: 'radar_')]
final class RadarController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $req): Response
    {
        $uf         = strtoupper(trim((string) $req->query->get('uf', '')));
        $municipio  = trim((string) $req->query->get('municipio', ''));
        $resultado  = trim((string) $req->query->get('resultado', ''));
        $tipo       = trim((string) $req->query->get('tipo', ''));
        $validade   = trim((string) $req->query->get('validade', ''));
        $page       = max(1, (int) $req->query->get('page', 1));
        $offset     = ($page - 1) * self::PER_PAGE;

        [$where, $params] = $this->buildWhere($uf, $municipio, $resultado, $tipo, $validade);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM radar_medidor" . ($where ? " WHERE $where" : ''),
            $params
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, sigla_uf, estado, municipio, local_verificacao,
                    data_ultima_verificacao, data_validade, ultimo_resultado,
                    tipo_medidor, proprietario_nome
             FROM radar_medidor"
            . ($where ? " WHERE $where" : '')
            . " ORDER BY sigla_uf, municipio, local_verificacao
               LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );

        $ufs       = $this->fetchDistinct('SELECT DISTINCT sigla_uf FROM radar_medidor ORDER BY sigla_uf');
        $resultados = $this->fetchDistinct('SELECT DISTINCT ultimo_resultado FROM radar_medidor WHERE ultimo_resultado IS NOT NULL ORDER BY ultimo_resultado');
        $tipos      = $this->fetchDistinct('SELECT DISTINCT tipo_medidor FROM radar_medidor WHERE tipo_medidor IS NOT NULL ORDER BY tipo_medidor');

        $stats = $this->db->fetchAssociative(
            "SELECT
                COUNT(*) AS total,
                SUM(ultimo_resultado = 'APROVADO') AS aprovados,
                SUM(ultimo_resultado = 'REPROVADO') AS reprovados,
                COUNT(DISTINCT sigla_uf) AS estados
             FROM radar_medidor"
        );

        return $this->render('radar/index.html.twig', [
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => self::PER_PAGE,
            'pages'     => (int) ceil($total / self::PER_PAGE),
            'filters'   => compact('uf', 'municipio', 'resultado', 'tipo', 'validade'),
            'ufs'       => $ufs,
            'resultados'=> $resultados,
            'tipos'     => $tipos,
            'stats'     => $stats,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'])]
    public function show(int $id): Response
    {
        $radar = $this->db->fetchAssociative(
            'SELECT * FROM radar_medidor WHERE id = ?', [$id]
        );

        if (!$radar) {
            throw $this->createNotFoundException('Radar não encontrado.');
        }

        $faixas = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_faixa WHERE radar_medidor_id = ? ORDER BY numero_faixa',
            [$id]
        );

        $historico = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_historico WHERE radar_medidor_id = ? ORDER BY ano DESC, data_laudo DESC',
            [$id]
        );

        // Decodifica JSON se vier como string
        if (is_string($radar['faixas_json'])) {
            $radar['faixas_json'] = json_decode($radar['faixas_json'], true) ?? [];
        }
        if (is_string($radar['historico_json'])) {
            $radar['historico_json'] = json_decode($radar['historico_json'], true) ?? [];
        }

        return $this->render('radar/show.html.twig', [
            'radar'     => $radar,
            'faixas'    => $faixas,
            'historico' => $historico,
        ]);
    }

    // -------------------------------------------------------------------------

    private function buildWhere(string $uf, string $municipio, string $resultado, string $tipo, string $validade): array
    {
        $parts  = [];
        $params = [];

        if ($uf !== '') {
            $parts[]        = 'sigla_uf = :uf';
            $params['uf']   = $uf;
        }
        if ($municipio !== '') {
            $parts[]              = 'municipio LIKE :municipio';
            $params['municipio']  = "%$municipio%";
        }
        if ($resultado !== '') {
            $parts[]              = 'ultimo_resultado = :resultado';
            $params['resultado']  = $resultado;
        }
        if ($tipo !== '') {
            $parts[]        = 'tipo_medidor = :tipo';
            $params['tipo'] = $tipo;
        }
        if ($validade === 'vencido') {
            $parts[] = "data_validade < CURDATE()";
        } elseif ($validade === 'valido') {
            $parts[] = "data_validade >= CURDATE()";
        } elseif ($validade === '30dias') {
            $parts[] = "data_validade BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
        }

        return [implode(' AND ', $parts), $params];
    }

    private function fetchDistinct(string $sql): array
    {
        return array_column($this->db->fetchAllAssociative($sql), array_key_first(
            $this->db->fetchAssociative($sql . ' LIMIT 1') ?: ['v' => null]
        ));
    }
}
