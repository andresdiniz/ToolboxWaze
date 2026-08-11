<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\DashboardDataProvider;
use App\Service\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard', name: 'app_dashboard')]
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardDataProvider $dataProvider,
        private readonly DashboardService $dashService,
    ) {}

    #[Route('', name: '')]
    public function index(): Response
    {
        $user = $this->getUser();
        $allowedUfs = $user instanceof User ? $user->getUfsForQuery() : null;
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        // Dados agregados com cache
        $radarKpis = $this->dataProvider->getRadarKpis($allowedUfs);
        $radarPorUf = $this->dataProvider->getRadarPorUf($allowedUfs);
        $radarResultado = $this->dataProvider->getRadarResultado($allowedUfs);
        $radarMensais = $this->dataProvider->getRadarMensais($allowedUfs);
        $radarCobertura = $this->dataProvider->getRadarCobertura($allowedUfs);
        $radarSemWaze = $this->dataProvider->getRadarSemWaze($allowedUfs, 8);

        $postoKpis = $this->dataProvider->getPostoKpis($allowedUfs);
        $postoAtividade = $this->dataProvider->getPostoAtividade($allowedUfs);

        $escolaKpis = $this->dataProvider->getEscolaKpis();
        $solicKpis = $this->dataProvider->getSolicitacaoKpis();
        $solicDiarias = $this->dataProvider->getSolicitacoesDiarias();
        $estadosAtivos = $this->dataProvider->getEstadosAtivos();

        $usuarioKpis = $isAdmin ? $this->dataProvider->getUsuarioKpis() : null;

        return $this->render('dashboard/index.html.twig', [
            'radarKpis' => $radarKpis,
            'radarPorUf' => $radarPorUf,
            'radarResultado' => $radarResultado,
            'radarMensais' => $radarMensais,
            'radarCobertura' => $radarCobertura,
            'radarSemWaze' => $radarSemWaze,
            'postoKpis' => $postoKpis,
            'postoAtividade' => $postoAtividade,
            'escolaKpis' => $escolaKpis,
            'solicKpis' => $solicKpis,
            'solicDiarias' => $solicDiarias,
            'estadosAtivos' => $estadosAtivos,
            'usuarioKpis' => $usuarioKpis,
            'isAdmin' => $isAdmin,
            'allowedUfs' => $allowedUfs,
        ]);
    }

    #[Route('/refresh', name: '_refresh', methods: ['GET'])]
    public function refresh(): JsonResponse
    {
        $user = $this->getUser();
        $allowedUfs = $user instanceof User ? $user->getUfsForQuery() : null;
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $radarKpis = $this->dataProvider->getRadarKpis($allowedUfs);
        $postoKpis = $this->dataProvider->getPostoKpis($allowedUfs);
        $escolaKpis = $this->dataProvider->getEscolaKpis();
        $solicKpis = $this->dataProvider->getSolicitacaoKpis();
        $usuarioKpis = $isAdmin ? $this->dataProvider->getUsuarioKpis() : null;

        return $this->json([
            'radares_vencidos' => $radarKpis->vencidos,
            'radares_total'    => $radarKpis->total,
            'postos_total'     => $postoKpis->total,
            'postos_com_waze'  => $postoKpis->comWaze,
            'postos_sem_waze'  => $postoKpis->semWaze,
            'escolas_total'    => $escolaKpis['total'] ?? 0,
            'escolas_estados'  => $escolaKpis['estados'] ?? 0,
            'solic_total'      => $solicKpis['geral']['total'] ?? 0,
            'solic_pendentes'  => $solicKpis['geral']['pendentes'] ?? 0,
            'solic_atendidas'  => $solicKpis['geral']['atendidas'] ?? 0,
            'solic_recusadas'  => $solicKpis['geral']['recusadas'] ?? 0,
            'solic_hoje'       => $solicKpis['geral']['hoje'] ?? 0,
            'usuarios_total'   => $usuarioKpis['total'] ?? 0,
            'usuarios_ativos'  => $usuarioKpis['ativos'] ?? 0,
            'usuarios_aprovados' => $usuarioKpis['aprovados'] ?? 0,
            'usuarios_pendentes' => $usuarioKpis['pendentes'] ?? 0,
        ]);
    }
}