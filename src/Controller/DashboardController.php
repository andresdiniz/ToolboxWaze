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
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Usuário inválido.');
        }

        $allowedUfs = $user->getUfsForQuery();
        $isAdmin = $user->isAdmin();

        // Permissões de módulo – usando hasPermission() diretamente
        $showRadar = $user->hasPermission(User::PERMISSION_RADARES);
        $showPosto = $user->hasPermission(User::PERMISSION_POSTOS);
        $showEscola = $user->hasPermission(User::PERMISSION_ESCOLAS);
        // Solicitações: apenas administradores (ou crie a constante)
        $showSolicitacao = $isAdmin; // ou $user->hasPermission(User::PERMISSION_SOLICITACOES) se criada

        // Carrega dados apenas se o usuário tiver permissão
        $radarKpis = $showRadar ? $this->dataProvider->getRadarKpis($allowedUfs) : null;
        $radarPorUf = $showRadar ? $this->dataProvider->getRadarPorUf($allowedUfs) : null;
        $radarResultado = $showRadar ? $this->dataProvider->getRadarResultado($allowedUfs) : null;
        $radarMensais = $showRadar ? $this->dataProvider->getRadarMensais($allowedUfs) : null;
        $radarCobertura = $showRadar ? $this->dataProvider->getRadarCobertura($allowedUfs) : null;
        $radarSemWaze = $showRadar ? $this->dataProvider->getRadarSemWaze($allowedUfs, 8) : [];

        $postoKpis = $showPosto ? $this->dataProvider->getPostoKpis($allowedUfs) : null;
        $postoAtividade = $showPosto ? $this->dataProvider->getPostoAtividade($allowedUfs) : null;

        $escolaKpis = $showEscola ? $this->dataProvider->getEscolaKpis() : null;
        
        $solicKpis = $showSolicitacao ? $this->dataProvider->getSolicitacaoKpis() : null;
        $solicDiarias = $showSolicitacao ? $this->dataProvider->getSolicitacoesDiarias() : null;
        
        $estadosAtivos = $this->dataProvider->getEstadosAtivos(); // público
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
            'showRadar' => $showRadar,
            'showPosto' => $showPosto,
            'showEscola' => $showEscola,
            'showSolicitacao' => $showSolicitacao,
        ]);
    }

    #[Route('/refresh', name: '_refresh', methods: ['GET'])]
    public function refresh(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Usuário inválido'], 403);
        }

        $allowedUfs = $user->getUfsForQuery();
        $isAdmin = $user->isAdmin();

        $showRadar = $user->hasPermission(User::PERMISSION_RADARES);
        $showPosto = $user->hasPermission(User::PERMISSION_POSTOS);
        $showEscola = $user->hasPermission(User::PERMISSION_ESCOLAS);
        $showSolicitacao = $isAdmin;

        $data = [];
        if ($showRadar) {
            $radarKpis = $this->dataProvider->getRadarKpis($allowedUfs);
            $data['radares_vencidos'] = $radarKpis->vencidos;
            $data['radares_total']    = $radarKpis->total;
        }
        if ($showPosto) {
            $postoKpis = $this->dataProvider->getPostoKpis($allowedUfs);
            $data['postos_total']     = $postoKpis->total;
            $data['postos_com_waze']  = $postoKpis->comWaze;
            $data['postos_sem_waze']  = $postoKpis->semWaze;
        }
        if ($showEscola) {
            $escolaKpis = $this->dataProvider->getEscolaKpis();
            $data['escolas_total']    = $escolaKpis['total'] ?? 0;
            $data['escolas_estados']  = $escolaKpis['estados'] ?? 0;
        }
        if ($showSolicitacao) {
            $solicKpis = $this->dataProvider->getSolicitacaoKpis();
            $data['solic_total']      = $solicKpis['geral']['total'] ?? 0;
            $data['solic_pendentes']  = $solicKpis['geral']['pendentes'] ?? 0;
            $data['solic_atendidas']  = $solicKpis['geral']['atendidas'] ?? 0;
            $data['solic_recusadas']  = $solicKpis['geral']['recusadas'] ?? 0;
            $data['solic_hoje']       = $solicKpis['geral']['hoje'] ?? 0;
        }
        if ($isAdmin) {
            $usuarioKpis = $this->dataProvider->getUsuarioKpis();
            $data['usuarios_total']     = $usuarioKpis['total'] ?? 0;
            $data['usuarios_ativos']    = $usuarioKpis['ativos'] ?? 0;
            $data['usuarios_aprovados'] = $usuarioKpis['aprovados'] ?? 0;
            $data['usuarios_pendentes'] = $usuarioKpis['pendentes'] ?? 0;
        }

        return $this->json($data);
    }
}