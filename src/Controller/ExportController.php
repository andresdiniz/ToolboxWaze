<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PostoStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/exportar', name: 'export_')]
final class ExportController extends AbstractController
{
    public function __construct(
        private readonly PostoStatsService $stats,
    ) {}

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
}
