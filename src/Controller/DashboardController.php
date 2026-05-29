<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\DashboardService;
use App\Service\PostoStatsService;
use App\Service\RadarStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/', name: 'dashboard_')]
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly PostoStatsService $postoStats,
        private readonly RadarStatsService $radarStats,
        private readonly DashboardService  $dashService,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();
        $isAdmin    = $this->isGranted('ROLE_ADMIN');

        // ── Radares ──────────────────────────────────────────────
        $radarKpis      = $this->radarStats->getKpis($allowedUfs);
        $radarPorUf     = $this->radarStats->getPorUf($allowedUfs);
        $radarResultado = $this->radarStats->getPorResultado($allowedUfs);
        $radarMensais   = $this->radarStats->getVerificacoesMensais();
        $radarCobertura = $this->radarStats->getCoberturaWazePorUf($allowedUfs);
        $radarSemWaze   = $this->radarStats->getSemWazePrioritarios($allowedUfs, 8);

        // ── Postos ───────────────────────────────────────────────
        $postoKpis      = $this->postoStats->getKpis($allowedUfs);
        $postoAtividade = $this->postoStats->getAtividadeDiaria();

        // ── Globais (admin only ou todos) ────────────────────────
        $escolaKpis   = $this->dashService->getEscolaKpis();
        $usuarioKpis  = $isAdmin ? $this->dashService->getUsuarioKpis() : [];
        $solicKpis    = $this->dashService->getSolicitacaoKpis();
        $solicDiarias = $this->dashService->getSolicitacoesDiarias();
        $estadosAtivos = $this->dashService->getEstadosAtivos();

        return $this->render('dashboard/index.html.twig', [
            // Radares
            'radarKpis'      => $radarKpis,
            'radarPorUf'     => $radarPorUf,
            'radarResultado' => $radarResultado,
            'radarMensais'   => $radarMensais,
            'radarCobertura' => $radarCobertura,
            'radarSemWaze'   => $radarSemWaze,
            // Postos
            'postoKpis'      => $postoKpis,
            'postoAtividade' => $postoAtividade,
            // Globais
            'escolaKpis'    => $escolaKpis,
            'usuarioKpis'   => $usuarioKpis,
            'solicKpis'     => $solicKpis,
            'solicDiarias'  => $solicDiarias,
            'estadosAtivos' => $estadosAtivos,
            'isAdmin'       => $isAdmin,
            'allowedUfs'    => $allowedUfs,
        ]);
    }
}
