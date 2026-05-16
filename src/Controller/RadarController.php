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
        $uf          = strtoupper(trim((string) $req->query->get('uf', '')));
        $municipio   = trim((string) $req->query->get('municipio', ''));
        $resultado   = trim((string) $req->query->get('resultado', ''));
        $tipo        = trim((string) $req->query->get('tipo', ''));
        $validade    = trim((string) $req->query->get('validade', ''));
        $serie       = trim((string) $req->query->get('serie', ''));
        $page        = max(1, (int) $req->query->get('page', 1));
        $offset      = ($page - 1) * self::PER_PAGE;

        [$where, $params] = $this->buildWhere($uf, $municipio, $resultado, $tipo, $validade, $serie);

        $baseFrom = $this->buildFrom($serie);
        $whereClause = $where ? " WHERE $where" : '';

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT rm.id) FROM radar_medidor rm $baseFrom $whereClause",
            $params
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT DISTINCT rm.id, rm.sigla_uf, rm.estado, rm.municipio,
                    rm.local_verificacao, rm.data_ultima_verificacao,
                    rm.data_validade, rm.ultimo_resultado,
                    rm.tipo_medidor, rm.proprietario_nome
             FROM radar_medidor rm $baseFrom
             $whereClause
             ORDER BY rm.sigla_uf, rm.municipio, rm.local_verificacao
             LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );

        $ufs        = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT sigla_uf FROM radar_medidor WHERE sigla_uf IS NOT NULL ORDER BY sigla_uf'
        ), 'sigla_uf');

        $resultados = array_column($this->db->fetchAllAssociative(
            "SELECT DISTINCT ultimo_resultado FROM radar_medidor WHERE ultimo_resultado IS NOT NULL ORDER BY ultimo_resultado"
        ), 'ultimo_resultado');

        $tipos      = array_column($this->db->fetchAllAssociative(
            "SELECT DISTINCT tipo_medidor FROM radar_medidor WHERE tipo_medidor IS NOT NULL ORDER BY tipo_medidor"
        ), 'tipo_medidor');

        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');

        $stats = $this->db->fetchAssociative(
            "SELECT
                COUNT(*) AS total,
                SUM(ultimo_resultado = 'APROVADO')  AS aprovados,
                SUM(ultimo_resultado = 'REPROVADO') AS reprovados,
                SUM(data_validade < :hoje)           AS vencidos,
                SUM(data_validade BETWEEN :hoje AND :em30) AS vencendo,
                COUNT(DISTINCT sigla_uf)             AS estados
             FROM radar_medidor",
            ['hoje' => $hoje, 'em30' => $em30]
        );

        return $this->render('radar/index.html.twig', [
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => self::PER_PAGE,
            'pages'      => (int) ceil($total / self::PER_PAGE),
            'filters'    => compact('uf', 'municipio', 'resultado', 'tipo', 'validade', 'serie'),
            'ufs'        => $ufs,
            'resultados' => $resultados,
            'tipos'      => $tipos,
            'stats'      => $stats,
            'hoje'       => $hoje,
            'em30'       => $em30,
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

        return $this->render('radar/show.html.twig', [
            'radar'     => $radar,
            'faixas'    => $faixas,
            'historico' => $historico,
        ]);
    }

    // -------------------------------------------------------------------------

    private function buildFrom(string $serie): string
    {
        if ($serie !== '') {
            return 'LEFT JOIN radar_faixa rf ON rf.radar_medidor_id = rm.id';
        }
        return '';
    }

    private function buildWhere(
        string $uf,
        string $municipio,
        string $resultado,
        string $tipo,
        string $validade,
        string $serie,
    ): array {
        $parts  = [];
        $params = [];

        if ($uf !== '') {
            $parts[]        = 'rm.sigla_uf = :uf';
            $params['uf']   = $uf;
        }
        if ($municipio !== '') {
            $parts[]             = 'rm.municipio LIKE :municipio';
            $params['municipio'] = "%$municipio%";
        }
        if ($resultado !== '') {
            $parts[]             = 'rm.ultimo_resultado = :resultado';
            $params['resultado'] = $resultado;
        }
        if ($tipo !== '') {
            $parts[]        = 'rm.tipo_medidor = :tipo';
            $params['tipo'] = $tipo;
        }
        if ($serie !== '') {
            $parts[]         = '(rf.numero_serie LIKE :serie OR rf.numero_inmetro LIKE :serie)';
            $params['serie'] = "%$serie%";
        }

        // Filtro de validade usando parâmetros para evitar SQL dinâmico com CURDATE()
        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');

        if ($validade === 'vencido') {
            $parts[]               = 'rm.data_validade < :val_hoje';
            $params['val_hoje']    = $hoje;
        } elseif ($validade === 'valido') {
            $parts[]               = 'rm.data_validade >= :val_hoje';
            $params['val_hoje']    = $hoje;
        } elseif ($validade === '30dias') {
            $parts[]               = 'rm.data_validade BETWEEN :val_hoje AND :val_em30';
            $params['val_hoje']    = $hoje;
            $params['val_em30']    = $em30;
        }

        return [implode(' AND ', $parts), $params];
    }
}
