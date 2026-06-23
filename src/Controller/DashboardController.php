<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\DashboardService;
use App\Service\PostoStatsService;
use App\Service\RadarStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\DBAL\Connection;

#[Route('/dashboard', name: 'app_dashboard')]
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly PostoStatsService $postoStats,
        private readonly RadarStatsService $radarStats,
        private readonly DashboardService  $dashService,
        private readonly Connection        $db,
    ) {}

    #[Route('', name: '')]
    public function index(): Response
    {
        /** @var User|null $user */
        $user          = $this->getUser();
        $allowedUfs    = $user?->getUfsForQuery();
        $isAdmin       = $this->isGranted('ROLE_ADMIN');

        $radarKpis      = $this->radarStats->getKpis($allowedUfs);
        $radarPorUf     = $this->radarStats->getPorUf($allowedUfs);
        $radarResultado = $this->radarStats->getPorResultado($allowedUfs);
        $radarMensais   = $this->radarStats->getVerificacoesMensais();
        $radarCobertura = $this->radarStats->getCoberturaWazePorUf($allowedUfs);
        $radarSemWaze   = $this->radarStats->getSemWazePrioritarios($allowedUfs, 8);

        $postoKpis      = $this->postoStats->getKpis($allowedUfs);
        $postoAtividade = $this->postoStats->getAtividadeDiaria();

        $escolaKpis    = $this->dashService->getEscolaKpis();
        $usuarioKpis   = $isAdmin ? $this->dashService->getUsuarioKpis() : [];
        $solicKpis     = $this->dashService->getSolicitacaoKpis();
        $solicDiarias  = $this->dashService->getSolicitacoesDiarias();
        $estadosAtivos = $this->dashService->getEstadosAtivos();

        return $this->render('dashboard/index.html.twig', [
            'radarKpis'      => $radarKpis,
            'radarPorUf'     => $radarPorUf,
            'radarResultado' => $radarResultado,
            'radarMensais'   => $radarMensais,
            'radarCobertura' => $radarCobertura,
            'radarSemWaze'   => $radarSemWaze,
            'postoKpis'      => $postoKpis,
            'postoAtividade' => $postoAtividade,
            'escolaKpis'     => $escolaKpis,
            'usuarioKpis'    => $usuarioKpis,
            'solicKpis'      => $solicKpis,
            'solicDiarias'   => $solicDiarias,
            'estadosAtivos'  => $estadosAtivos,
            'isAdmin'        => $isAdmin,
            'allowedUfs'     => $allowedUfs,
        ]);
    }

    /**
     * #9 – Endpoint SSE que empurra KPIs em tempo real.
     *
     * Emite um evento SSE a cada 15 segundos com as contagens mais recentes
     * de radares pendentes, solicitações abertas e erros JS do dia.
     * O cliente desconecta quando fechar/recarregar a página.
     */
    #[Route('/stream', name: '_stream')]
    public function stream(): StreamedResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();
        $isAdmin    = $this->isGranted('ROLE_ADMIN');

        $db = $this->db;

        $response = new StreamedResponse(function () use ($db, $allowedUfs, $isAdmin) {
            $ufWhere  = '';
            $ufParams = [];

            if ($allowedUfs !== null) {
                if (count($allowedUfs) === 0) {
                    $ufWhere = 'AND 1=0';
                } else {
                    $ph       = implode(',', array_fill(0, count($allowedUfs), '?'));
                    $ufWhere  = "AND sigla_uf IN ($ph)";
                    $ufParams = $allowedUfs;
                }
            }

            $maxIterations = 60; // ~15 min de conexão máxima
            $iteration     = 0;

            while ($iteration < $maxIterations && !connection_aborted()) {
                try {
                    // Radares com validade vencida
                    $radaresVencidos = (int) $db->fetchOne(
                        "SELECT COUNT(*) FROM radar_medidor
                         WHERE STR_TO_DATE(data_validade, '%d/%m/%Y') < CURDATE() $ufWhere",
                        $ufParams
                    );

                    // Solicitações abertas
                    $solicAbertasVal = $isAdmin
                        ? (int) $db->fetchOne("SELECT COUNT(*) FROM solicitacao WHERE status = 'aberta'")
                        : 0;

                    // Erros JS do dia (monitoring)
                    $errosJs = (int) $db->fetchOne(
                        "SELECT COUNT(*) FROM monitoring_event
                         WHERE type IN ('js_error','unhandled_rejection','ajax_error')
                           AND created_at >= CURDATE()"
                    );

                    $payload = json_encode([
                        'radares_vencidos' => $radaresVencidos,
                        'solic_abertas'    => $solicAbertasVal,
                        'erros_js'         => $errosJs,
                        'ts'               => time(),
                    ]);

                    echo 'event: kpi' . PHP_EOL;
                    echo 'data: ' . $payload . PHP_EOL . PHP_EOL;
                    ob_flush();
                    flush();
                } catch (\Throwable) {
                    // Ignora erros de DB — o cliente vai tentar na próxima iteração
                }

                $iteration++;
                sleep(15);
            }

            // Sinaliza encerramento
            echo 'event: close' . PHP_EOL;
            echo 'data: {}' . PHP_EOL . PHP_EOL;
            ob_flush();
            flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no'); // nginx

        return $response;
    }
}
