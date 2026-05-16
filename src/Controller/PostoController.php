<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/postos', name: 'posto_')]
final class PostoController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $req): Response
    {
        $uf        = strtoupper(trim((string) $req->query->get('uf', '')));
        $municipio = trim((string) $req->query->get('municipio', ''));
        $bandeira  = trim((string) $req->query->get('bandeira', ''));
        $busca     = trim((string) $req->query->get('busca', ''));
        $page      = max(1, (int) $req->query->get('page', 1));
        $offset    = ($page - 1) * self::PER_PAGE;

        [$where, $params] = $this->buildWhere($uf, $municipio, $bandeira, $busca);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM fuel_reseller_raw" . ($where ? " WHERE $where" : ''),
            $params
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, razao_social, nome_fantasia, cnpj, bandeira,
                    endereco, complemento, bairro, cep, municipio, uf,
                    autorizacao, data_publicacao, data_vinculacao
             FROM fuel_reseller_raw"
            . ($where ? " WHERE $where" : '')
            . " ORDER BY uf, municipio, razao_social
               LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );

        $ufs       = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT uf FROM fuel_reseller_raw WHERE uf IS NOT NULL ORDER BY uf'
        ), 'uf');

        $bandeiras = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT bandeira FROM fuel_reseller_raw WHERE bandeira IS NOT NULL ORDER BY bandeira'
        ), 'bandeira');

        $stats = $this->db->fetchAssociative(
            "SELECT
                COUNT(*) AS total,
                COUNT(DISTINCT uf) AS estados,
                COUNT(DISTINCT municipio) AS municipios,
                COUNT(DISTINCT bandeira) AS bandeiras
             FROM fuel_reseller_raw"
        );

        return $this->render('posto/index.html.twig', [
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => self::PER_PAGE,
            'pages'     => (int) ceil($total / self::PER_PAGE),
            'filters'   => compact('uf', 'municipio', 'bandeira', 'busca'),
            'ufs'       => $ufs,
            'bandeiras' => $bandeiras,
            'stats'     => $stats,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'])]
    public function show(int $id): Response
    {
        $posto = $this->db->fetchAssociative(
            'SELECT * FROM fuel_reseller_raw WHERE id = ?', [$id]
        );

        if (!$posto) {
            throw $this->createNotFoundException('Posto não encontrado.');
        }

        if (is_string($posto['raw_data'])) {
            $posto['raw_data'] = json_decode($posto['raw_data'], true) ?? [];
        }

        return $this->render('posto/show.html.twig', [
            'posto' => $posto,
        ]);
    }

    // -------------------------------------------------------------------------

    private function buildWhere(string $uf, string $municipio, string $bandeira, string $busca): array
    {
        $parts  = [];
        $params = [];

        if ($uf !== '') {
            $parts[]      = 'uf = :uf';
            $params['uf'] = $uf;
        }
        if ($municipio !== '') {
            $parts[]             = 'municipio LIKE :municipio';
            $params['municipio'] = "%$municipio%";
        }
        if ($bandeira !== '') {
            $parts[]             = 'bandeira = :bandeira';
            $params['bandeira']  = $bandeira;
        }
        if ($busca !== '') {
            $parts[]          = '(razao_social LIKE :busca OR nome_fantasia LIKE :busca OR cnpj LIKE :busca)';
            $params['busca']  = "%$busca%";
        }

        return [implode(' AND ', $parts), $params];
    }
}
