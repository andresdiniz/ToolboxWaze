<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\RadarFaixaRepository;
use App\Repository\RadarHistoricoRepository;
use App\Repository\RadarMedidorRepository;
use App\Repository\RadarWazeLinkRepository;
use App\Service\RadarStatsService;
use App\Service\RadarWazeLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/radares', name: 'radar_')]
final class RadarController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly RadarMedidorRepository  $radarRepo,
        private readonly RadarWazeLinkRepository $wazeRepo,
        private readonly RadarFaixaRepository    $faixaRepo,
        private readonly RadarHistoricoRepository $historicoRepo,
        private readonly RadarStatsService        $statsService,
        private readonly RadarWazeLinkService     $wazeLinkService,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $req): Response
    {
        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $filters = [
            'uf'        => strtoupper(trim((string) $req->query->get('uf', ''))),
            'municipio' => trim((string) $req->query->get('municipio', '')),
            'resultado' => trim((string) $req->query->get('resultado', '')),
            'tipo'      => trim((string) $req->query->get('tipo', '')),
            'validade'  => trim((string) $req->query->get('validade', '')),
            'serie'     => trim((string) $req->query->get('serie', '')),
        ];

        // Garante que o usuário não filtre por UF fora de suas permissões
        if ($filters['uf'] !== '' && $allowedUfs !== null && !in_array($filters['uf'], $allowedUfs, true)) {
            $filters['uf'] = '';
        }

        $page  = max(1, (int) $req->query->get('page', 1));
        $total = $this->radarRepo->countFiltered($filters, $allowedUfs);
        $rows  = $this->radarRepo->findPaginated($filters, $allowedUfs, $page, self::PER_PAGE);

        $filterOptions = $this->radarRepo->findFilterOptions($allowedUfs);
        $stats         = $this->statsService->getKpis($allowedUfs);

        $hoje     = (new \DateTimeImmutable())->format('Y-m-d');
        $em30     = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $ha30dias = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        return $this->render('radar/index.html.twig', [
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => self::PER_PAGE,
            'pages'      => (int) ceil($total / self::PER_PAGE),
            'filters'    => $filters,
            'ufs'        => $filterOptions['ufs'],
            'resultados' => $filterOptions['resultados'],
            'tipos'      => $filterOptions['tipos'],
            'stats'      => $stats,
            'hoje'       => $hoje,
            'em30'       => $em30,
            'ha30dias'   => $ha30dias,
            'allowedUfs' => $allowedUfs,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'])]
    public function show(int $id, Request $req): Response
    {
        /** @var User|null $user */
        $user  = $this->getUser();
        $radar = $this->radarRepo->findRawById($id);

        if (!$radar) {
            throw $this->createNotFoundException('Radar não encontrado.');
        }

        if ($user && !$user->canAccessUf((string) ($radar['sigla_uf'] ?? ''))) {
            throw $this->createAccessDeniedException('Você não tem acesso a dados deste estado.');
        }

        $faixas    = $this->faixaRepo->findBy(['radarMedidorId' => $id], ['numeroFaixa' => 'ASC']);
        $historico = $this->historicoRepo->findBy(['radarMedidorId' => $id], ['ano' => 'DESC', 'dataLaudo' => 'DESC']);

        $wazeLink = $this->wazeRepo->findRawByRadarId($id);
        $wazeLog  = $wazeLink ? $this->wazeRepo->findLogByLinkId($wazeLink['id']) : [];

        $session      = $req->getSession();
        $wazeErrors   = $session->remove('_waze_errors_' . $id) ?? [];
        $wazeFormData = $session->remove('_waze_form_'   . $id) ?? [];

        $hoje     = (new \DateTimeImmutable())->format('Y-m-d');
        $em30     = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $ha30dias = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        return $this->render('radar/show.html.twig', [
            'radar'        => $radar,
            'faixas'       => $faixas,
            'historico'    => $historico,
            'wazeLink'     => $wazeLink,
            'wazeLog'      => $wazeLog,
            'wazeErrors'   => $wazeErrors,
            'wazeFormData' => $wazeFormData,
            'hoje'         => $hoje,
            'em30'         => $em30,
            'ha30dias'     => $ha30dias,
        ]);
    }

    #[Route('/{id}/waze-salvar', name: 'waze_save', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function wazeSave(int $id, Request $req): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        if (!$this->isCsrfTokenValid('waze_save_' . $id, $req->request->get('_token'))) {
            $this->addFlash('error', 'Token de segurança inválido.');
            return $this->redirectToRoute('radar_show', ['id' => $id]);
        }

        $radar = $this->radarRepo->findRawById($id);
        if (!$radar) {
            throw $this->createNotFoundException('Radar não encontrado.');
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$user->canAccessUf((string) ($radar['sigla_uf'] ?? ''))) {
            throw $this->createAccessDeniedException('Você não tem acesso a dados deste estado.');
        }

        $wazeLink      = trim((string) $req->request->get('waze_link', ''));
        $motivoRevisao = trim((string) $req->request->get('motivo_revisao', ''));
        $isUpdate      = $this->wazeRepo->findRawByRadarId($id) !== null;

        $errors = $this->wazeLinkService->validate($wazeLink, $motivoRevisao, $isUpdate);

        if ($errors !== []) {
            $req->getSession()->set('_waze_errors_' . $id, $errors);
            $req->getSession()->set('_waze_form_'   . $id, [
                'waze_link'      => $wazeLink,
                'motivo_revisao' => $motivoRevisao,
            ]);
            return $this->redirectToRoute('radar_show', ['id' => $id, '_fragment' => 'waze-form-collapse']);
        }

        $this->wazeLinkService->save($id, $wazeLink, $motivoRevisao, $user);

        $this->addFlash('success', $isUpdate ? 'Link Waze atualizado com sucesso.' : 'Link Waze cadastrado com sucesso.');

        return $this->redirectToRoute('radar_show', ['id' => $id]);
    }
}
