<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PostoStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'dashboard_')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly PostoStatsService $stats,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $kpis      = $this->stats->getKpis($allowedUfs);
        $atividade = $this->stats->getAtividadeDiaria();

        return $this->render('dashboard/index.html.twig', [
            'kpis'      => $kpis,
            'atividade' => $atividade,
        ]);
    }
}
