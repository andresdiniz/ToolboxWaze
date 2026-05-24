<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Página de busca global */
#[Route('/busca', name: 'busca_')]
final class BuscaController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('', name: 'index')]
    public function index(Request $req): Response
    {
        $q = trim((string) $req->query->get('q', ''));

        if (strlen($q) < 2) {
            return $this->render('busca/index.html.twig', [
                'q'       => $q,
                'radares' => [],
                'postos'  => [],
            ]);
        }

        $like = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q) . '%';

        $radares = $this->db->fetchAllAssociative(
            "SELECT id, sigla_uf, municipio, local_verificacao, ultimo_resultado, tipo_medidor
             FROM radar_medidor
             WHERE municipio LIKE ? OR local_verificacao LIKE ? OR proprietario_nome LIKE ?
             ORDER BY sigla_uf, municipio LIMIT 30",
            [$like, $like, $like]
        );

        $postos = $this->db->fetchAllAssociative(
            "SELECT id, uf, municipio, razao_social, nome_fantasia, bandeira, status
             FROM fuel_reseller_raw
             WHERE razao_social LIKE ? OR nome_fantasia LIKE ? OR municipio LIKE ?
             ORDER BY uf, municipio LIMIT 30",
            [$like, $like, $like]
        );

        return $this->render('busca/index.html.twig', [
            'q'       => $q,
            'radares' => $radares,
            'postos'  => $postos,
        ]);
    }
}
