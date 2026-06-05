<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\AccessControlTrait;
use App\Entity\User;
use App\Service\RadarService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/radares')]
#[IsGranted('ROLE_USER')]
class RadarController extends AbstractController
{
    use AccessControlTrait;

    private const PER_PAGE = 50;

    public function __construct(
        private readonly Connection   $db,
        private readonly RadarService $radarService
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Listagem principal
    // ─────────────────────────────────────────────────────────────────────────
    #[Route('', name: 'radar_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_RADARES);

        $filters = [
            'uf'        => trim((string) $request->query->get('uf', '')),
            'municipio' => trim((string) $request->query->get('municipio', '')),
            'resultado' => trim((string) $request->query->get('resultado', '')),
            'tipo'      => trim((string) $request->query->get('tipo', '')),
            'validade'  => trim((string) $request->query->get('validade', '')),
            'serie'     => trim((string) $request->query->get('serie', '')),
        ];

        if ($filters['uf'] !== '') {
            $this->requireUfAccess($filters['uf']);
        }

        $allowedUfs  = $this->allowedUfsForView();
        $page        = max(1, (int) $request->query->get('page', 1));
        $paginated   = $this->radarService->findPaginated($filters, $page, self::PER_PAGE, $allowedUfs);

        $semFiltros = array_filter($filters) === [] && $allowedUfs === null;
        $stats      = $semFiltros ? $this->radarService->getStats() : null;

        $totalMesclados = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM radar_medidor WHERE merged_into_id IS NOT NULL'
        );

        $ufsQuery = $allowedUfs !== null
            ? 'SELECT DISTINCT sigla_uf FROM radar_medidor WHERE sigla_uf IS NOT NULL AND merged_into_id IS NULL AND sigla_uf IN (?' . str_repeat(',?', count($allowedUfs) - 1) . ') ORDER BY sigla_uf'
            : 'SELECT DISTINCT sigla_uf FROM radar_medidor WHERE sigla_uf IS NOT NULL AND merged_into_id IS NULL ORDER BY sigla_uf';

        $ufs = array_column(
            $this->db->fetchAllAssociative($ufsQuery, $allowedUfs ?? []),
            'sigla_uf'
        );
        $resultados = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT situacao FROM radar_medidor WHERE situacao IS NOT NULL AND merged_into_id IS NULL ORDER BY situacao'
        ), 'situacao');
        $tipos = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT tipo_medidor FROM radar_medidor WHERE tipo_medidor IS NOT NULL AND merged_into_id IS NULL ORDER BY tipo_medidor'
        ), 'tipo_medidor');

        return $this->render('radar/index.html.twig', [
            'rows'           => $paginated['rows'],
            'page'           => $paginated['page'],
            'pages'          => $paginated['pages'],
            'total'          => $paginated['total'],
            'per_page'       => self::PER_PAGE,
            'stats'          => $stats,
            'ufs'            => $ufs,
            'resultados'     => $resultados,
            'tipos'          => $tipos,
            'hoje'           => (new \DateTimeImmutable())->format('Y-m-d'),
            'em30'           => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'ha30dias'       => (new \DateTimeImmutable('-30 days'))->format('Y-m-d'),
            'filters'        => $filters,
            'allowedUfs'     => $allowedUfs,
            'totalMesclados' => $totalMesclados,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Aba: radares mesclados
    // ─────────────────────────────────────────────────────────────────────────
    #[Route('/mesclados', name: 'radar_mesclados', methods: ['GET'])]
    public function mesclados(Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_RADARES);

        $page      = max(1, (int) $request->query->get('page', 1));
        $paginated = $this->radarService->getMescladosPaginated($page, self::PER_PAGE);

        return $this->render('radar/mesclados.html.twig', [
            'rows'  => $paginated['rows'],
            'page'  => $paginated['page'],
            'pages' => $paginated['pages'],
            'total' => $paginated['total'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Detalhe
    // ─────────────────────────────────────────────────────────────────────────
    #[Route('/{id}', name: 'radar_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(int $id): Response
    {
        $this->requirePermission(User::PERMISSION_RADARES);

        try {
            $data = $this->radarService->getShowData($id);
        } catch (\RuntimeException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        $this->requireUfAccess($data['radar']['sigla_uf'] ?? null);

        return $this->render('radar/show.html.twig', $data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Editar radar
    // ─────────────────────────────────────────────────────────────────────────
    #[Route('/{id}/editar', name: 'radar_edit', methods: ['GET', 'POST'], requirements: ['id' => '\\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_RADARES);

        try {
            $radar = $this->radarService->findOrFail($id);
        } catch (\RuntimeException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        $this->requireUfAccess($radar['sigla_uf'] ?? null);

        $estados    = $this->db->fetchAllAssociative('SELECT uf, name FROM brazilian_state ORDER BY uf');
        $estadosMap = array_column($estados, 'name', 'uf');

        if ($request->isMethod('POST')) {
            /** @var User $user */
            $user  = $this->getUser();
            $count = $this->radarService->saveEdit($id, $request->request->all(), $estadosMap, $user);

            if ($count > 0) {
                $this->addFlash('success', "$count campo(s) atualizado(s) com sucesso.");
            } else {
                $this->addFlash('info', 'Nenhuma alteração detectada.');
            }

            return $this->redirectToRoute('radar_show', ['id' => $id]);
        }

        $siglaUf    = $radar['sigla_uf'] ?? '';
        $estadoNome = $estadosMap[$siglaUf] ?? $radar['uf'] ?? '';

        return $this->render('radar/edit.html.twig', [
            'radar'           => $radar,
            'camposEditaveis' => $this->radarService->getCamposEditaveis(),
            'editLog'         => $this->radarService->getEditLog($id),
            'estados'         => $estados,
            'estadoNome'      => $estadoNome,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Salvar link Waze  (#4 — sem queries duplicadas: usa getShowData() em erro)
    // ─────────────────────────────────────────────────────────────────────────
    #[Route('/{id}/waze', name: 'radar_waze_save', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function wazeSave(int $id, Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_RADARES);

        $radar = $this->db->fetchAssociative('SELECT id, sigla_uf FROM radar_medidor WHERE id = ?', [$id]);
        if (!$radar) {
            throw $this->createNotFoundException();
        }
        $this->requireUfAccess($radar['sigla_uf'] ?? null);

        $wazeLink = trim((string) $request->request->get('waze_link', ''));
        $motivo   = trim((string) $request->request->get('motivo_revisao', '')) ?: null;

        /** @var User $user */
        $user = $this->getUser();

        try {
            $this->radarService->saveWazeLink($id, $wazeLink, $motivo, $user);
        } catch (\InvalidArgumentException $e) {
            // #4 — rerenderiza show sem repetir as queries manualmente
            $errors  = json_decode($e->getMessage(), true) ?? [];
            $showData = $this->radarService->getShowData($id);
            $showData['wazeErrors']   = $errors;
            $showData['wazeFormData'] = ['waze_link' => $wazeLink, 'motivo_revisao' => $motivo];

            return $this->render('radar/show.html.twig', $showData);
        }

        $this->addFlash('success', 'Link Waze salvo com sucesso.');
        return $this->redirectToRoute('radar_show', ['id' => $id]);
    }
}
