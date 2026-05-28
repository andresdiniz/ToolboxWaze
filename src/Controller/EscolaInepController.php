<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/escolas')]
#[IsGranted('ROLE_USER')]
class EscolaInepController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    #[Route('', name: 'escola_inep_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page      = max(1, (int) $request->query->get('page', 1));
        $busca     = trim((string) $request->query->get('busca', ''));
        $uf        = trim((string) $request->query->get('uf', ''));
        $municipio = trim((string) $request->query->get('municipio', ''));
        $dependencia = trim((string) $request->query->get('dependencia', ''));
        $localizacao = trim((string) $request->query->get('localizacao', ''));
        $offset    = ($page - 1) * self::PER_PAGE;

        $where  = ['1=1'];
        $params = [];

        if ($busca !== '') {
            $where[]  = '(e.escola LIKE ? OR e.codigo_inep LIKE ? OR e.municipio LIKE ?)';
            $params[] = "%$busca%";
            $params[] = "%$busca%";
            $params[] = "%$busca%";
        }
        if ($uf !== '') {
            $where[]  = 'e.uf = ?';
            $params[] = $uf;
        }
        if ($municipio !== '') {
            $where[]  = 'e.municipio LIKE ?';
            $params[] = "%$municipio%";
        }
        if ($dependencia !== '') {
            $where[]  = 'e.dependencia_administrativa = ?';
            $params[] = $dependencia;
        }
        if ($localizacao !== '') {
            $where[]  = 'e.localizacao = ?';
            $params[] = $localizacao;
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM escola_inep e WHERE $whereClause",
            $params
        );
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);

        $rows = $this->db->fetchAllAssociative(
            "SELECT e.id, e.escola, e.codigo_inep, e.uf, e.municipio,
                    e.localizacao, e.dependencia_administrativa,
                    e.categoria_administrativa, e.porte, e.etapas_ensino,
                    e.restricao_atendimento, e.latitude, e.longitude
             FROM escola_inep e
             WHERE $whereClause
             ORDER BY e.escola
             LIMIT $offset, " . self::PER_PAGE,
            $params
        );

        $stats = null;
        if ($busca === '' && $uf === '' && $municipio === '' && $dependencia === '' && $localizacao === '') {
            $stats = $this->db->fetchAssociative(
                "SELECT COUNT(*)                              AS total,
                        COUNT(DISTINCT e.uf)                  AS estados,
                        COUNT(DISTINCT CONCAT(e.uf,e.municipio)) AS municipios
                 FROM escola_inep e"
            ) ?: null;
        }

        $ufs = array_column(
            $this->db->fetchAllAssociative(
                "SELECT DISTINCT uf FROM escola_inep WHERE uf IS NOT NULL ORDER BY uf"
            ),
            'uf'
        );

        $dependencias = array_column(
            $this->db->fetchAllAssociative(
                "SELECT DISTINCT dependencia_administrativa FROM escola_inep
                 WHERE dependencia_administrativa IS NOT NULL ORDER BY dependencia_administrativa"
            ),
            'dependencia_administrativa'
        );

        $localizacoes = array_column(
            $this->db->fetchAllAssociative(
                "SELECT DISTINCT localizacao FROM escola_inep
                 WHERE localizacao IS NOT NULL ORDER BY localizacao"
            ),
            'localizacao'
        );

        return $this->render('escola_inep/index.html.twig', [
            'rows'         => $rows,
            'page'         => $page,
            'pages'        => $pages,
            'total'        => $total,
            'perPage'      => self::PER_PAGE,
            'stats'        => $stats,
            'ufs'          => $ufs,
            'dependencias' => $dependencias,
            'localizacoes' => $localizacoes,
            'filters'      => [
                'busca'       => $busca,
                'uf'          => $uf,
                'municipio'   => $municipio,
                'dependencia' => $dependencia,
                'localizacao' => $localizacao,
            ],
        ]);
    }

    #[Route('/{id}', name: 'escola_inep_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(int $id): Response
    {
        $escola = $this->db->fetchAssociative(
            'SELECT * FROM escola_inep WHERE id = ?',
            [$id]
        );

        if (!$escola) {
            throw $this->createNotFoundException("Escola #{$id} não encontrada.");
        }

        return $this->render('escola_inep/show.html.twig', [
            'escola' => $escola,
        ]);
    }
}
