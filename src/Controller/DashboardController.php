<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\PostoStatsService;
use App\Service\RadarStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'dashboard_')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly PostoStatsService $postoStats,
        private readonly RadarStatsService $radarStats,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        // Postos
        $postoKpis      = $this->postoStats->getKpis($allowedUfs);
        $postoAtividade = $this->postoStats->getAtividadeDiaria();

        // Radares
        $radarKpis       = $this->radarStats->getKpis($allowedUfs);
        $radarPorUf      = $this->radarStats->getPorUf($allowedUfs);
        $radarResultado  = $this->radarStats->getPorResultado($allowedUfs);
        $radarMensais    = $this->radarStats->getVerificacoesMensais();
        $radarCobertura  = $this->radarStats->getCoberturaWazePorUf($allowedUfs);
        $radarSemWaze    = $this->radarStats->getSemWazePrioritarios($allowedUfs, 10);

        return $this->render('dashboard/index.html.twig', [
            // legado (postos)
            'kpis'           => $postoKpis,
            'atividade'      => $postoAtividade,
            // radares
            'radarKpis'      => $radarKpis,
            'radarPorUf'     => $radarPorUf,
            'radarResultado' => $radarResultado,
            'radarMensais'   => $radarMensais,
            'radarCobertura' => $radarCobertura,
            'radarSemWaze'   => $radarSemWaze,
        ]);
    }
}
