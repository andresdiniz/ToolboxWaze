<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RadarWazeLink;
use App\Entity\RadarWazeLinkLog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/waze-links', name: 'admin_waze_link_')]
#[IsGranted('ROLE_ADMIN')]
final class RadarWazeLinkCrudController extends AbstractController
{
    private const PER_PAGE = 30;

    private const SORT_FIELDS = [
        'municipio'   => 'rm.municipio',
        'inserted_at' => 'wl.inserted_at',
        'updated_at'  => 'wl.updated_at',
        'hazard_id'   => 'wl.permanent_hazard_id',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
    ) {}

    // =========================================================================
    // LIST
    // =========================================================================

    #[Route('', name: 'index')]
    public function index(Request $req): Response
    {
        $search  = trim((string) $req->query->get('q', ''));
        $page    = max(1, (int) $req->query->get('page', 1));
        $offset  = ($page - 1) * self::PER_PAGE;

        // Ordenação
        $sortKey = $req->query->get('sort', 'inserted_at');
        $sortDir = strtoupper((string) $req->query->get('dir', 'DESC'));

        if (!array_key_exists($sortKey, self::SORT_FIELDS)) {
            $sortKey = 'inserted_at';
        }
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $sortDir = 'DESC';
        }

        $orderBy = self::SORT_FIELDS[$sortKey] . ' ' . $sortDir;

        $where  = '';
        $params = [];

        if ($search !== '') {
            $where  = 'WHERE (rm.municipio LIKE ? OR rm.local_verificacao LIKE ? OR wl.waze_link LIKE ? OR wl.permanent_hazard_id = ?)';
            $params = ["%$search%", "%$search%", "%$search%", is_numeric($search) ? (int) $search : -1];
        }

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM radar_waze_link wl
             JOIN radar_medidor rm ON rm.id = wl.radar_medidor_id
             $where",
            $params
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT wl.id, wl.waze_link, wl.permanent_hazard_id, wl.inserted_at, wl.updated_at,
                    wl.observacao,
                    rm.municipio, rm.sigla_uf, rm.local_verificacao,
                    ui.email AS inserted_by_email,
                    uu.email AS updated_by_email
             FROM radar_waze_link wl
             JOIN radar_medidor rm ON rm.id = wl.radar_medidor_id
             JOIN user ui ON ui.id = wl.inserted_by
             LEFT JOIN user uu ON uu.id = wl.updated_by
             $where
             ORDER BY $orderBy
             LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );

        return $this->render('admin/waze_link/index.html.twig', [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pages'    => (int) ceil($total / self::PER_PAGE),
            'per_page' => self::PER_PAGE,
            'search'   => $search,
            'sortKey'  => $sortKey,
            'sortDir'  => $sortDir,
        ]);
    }

    // =========================================================================
    // NEW
    // =========================================================================

    #[Route('/novo', name: 'new')]
    public function new(Request $req): Response
    {
        $errors     = [];
        $radarId    = (int) $req->request->get('radar_medidor_id', $req->query->get('radar_id', 0));
        $wazeLink   = trim((string) $req->request->get('waze_link', ''));
        $observacao = trim((string) $req->request->get('observacao', ''));

        if ($req->isMethod('POST')) {
            $errors = $this->validateLink($wazeLink);

            if ($radarId <= 0) {
                $errors['radar'] = 'Selecione um radar.';
            }

            if ($errors === []) {
                $radar = $this->em->find(\App\Entity\RadarMedidor::class, $radarId);

                if (!$radar) {
                    $errors['radar'] = 'Radar não encontrado.';
                } else {
                    // Verifica se já existe link para este radar
                    $exists = $this->db->fetchOne(
                        'SELECT id FROM radar_waze_link WHERE radar_medidor_id = ?',
                        [$radarId]
                    );

                    if ($exists) {
                        $errors['radar'] = 'Este radar já possui um link cadastrado. Edite o existente.';
                    } else {
                        $link = new RadarWazeLink();
                        $link->setRadarMedidor($radar)
                             ->setWazeLink($wazeLink)
                             ->setInsertedBy($this->getUser())
                             ->setInsertedAt(new \DateTimeImmutable())
                             ->setObservacao($observacao ?: null);

                        $this->em->persist($link);
                        $this->em->flush();

                        $this->addFlash('success', 'Link Waze cadastrado com sucesso.');

                        return $this->redirectToRoute('admin_waze_link_index');
                    }
                }
            }
        }

        // Para o select de radar: busca os que ainda não têm link
        $radares = $this->db->fetchAllAssociative(
            'SELECT rm.id, rm.municipio, rm.sigla_uf, rm.local_verificacao
             FROM radar_medidor rm
             LEFT JOIN radar_waze_link wl ON wl.radar_medidor_id = rm.id
             WHERE wl.id IS NULL
             ORDER BY rm.sigla_uf, rm.municipio, rm.local_verificacao
             LIMIT 500'
        );

        return $this->render('admin/waze_link/form.html.twig', [
            'title'      => 'Novo Link Waze',
            'action'     => $this->generateUrl('admin_waze_link_new'),
            'radares'    => $radares,
            'radarId'    => $radarId,
            'wazeLink'   => $wazeLink,
            'observacao' => $observacao,
            'errors'     => $errors,
            'isEdit'     => false,
        ]);
    }

    // =========================================================================
    // EDIT
    // =========================================================================

    #[Route('/{id}/editar', name: 'edit', requirements: ['id' => '\\d+'])]
    public function edit(int $id, Request $req): Response
    {
        $link = $this->em->find(RadarWazeLink::class, $id);

        if (!$link) {
            throw $this->createNotFoundException('Link não encontrado.');
        }

        $errors     = [];
        $wazeLink   = $req->isMethod('POST')
            ? trim((string) $req->request->get('waze_link', ''))
            : $link->getWazeLink();
        $observacao = $req->isMethod('POST')
            ? trim((string) $req->request->get('observacao', ''))
            : ($link->getObservacao() ?? '');

        if ($req->isMethod('POST')) {
            $errors = $this->validateLink($wazeLink);

            if ($errors === []) {
                $user = $this->getUser();
                $now  = new \DateTimeImmutable();

                // Grava log de cada campo alterado
                if ($wazeLink !== $link->getWazeLink()) {
                    $this->em->persist(RadarWazeLinkLog::create(
                        $link, $user, 'waze_link',
                        $link->getWazeLink(), $wazeLink
                    ));
                    $link->setWazeLink($wazeLink);
                }

                $novaObs = $observacao ?: null;
                if ($novaObs !== $link->getObservacao()) {
                    $this->em->persist(RadarWazeLinkLog::create(
                        $link, $user, 'observacao',
                        $link->getObservacao(), $novaObs
                    ));
                    $link->setObservacao($novaObs);
                }

                $link->setUpdatedBy($user)->setUpdatedAt($now);

                $this->em->flush();

                $this->addFlash('success', 'Link Waze atualizado com sucesso.');

                return $this->redirectToRoute('admin_waze_link_index');
            }
        }

        return $this->render('admin/waze_link/form.html.twig', [
            'title'      => 'Editar Link Waze',
            'action'     => $this->generateUrl('admin_waze_link_edit', ['id' => $id]),
            'radares'    => [],
            'radarId'    => $link->getRadarMedidor()->getId(),
            'radarLabel' => sprintf('%s/%s — %s',
                $link->getRadarMedidor()->getMunicipio(),
                $link->getRadarMedidor()->getSiglaUf(),
                $link->getRadarMedidor()->getLocalVerificacao()
            ),
            'wazeLink'   => $wazeLink,
            'observacao' => $observacao,
            'errors'     => $errors,
            'isEdit'     => true,
            'link'       => $link,
        ]);
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    #[Route('/{id}/excluir', name: 'delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(int $id, Request $req): Response
    {
        $link = $this->em->find(RadarWazeLink::class, $id);

        if (!$link) {
            throw $this->createNotFoundException('Link não encontrado.');
        }

        if (!$this->isCsrfTokenValid('delete_waze_link_' . $id, $req->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_waze_link_index');
        }

        $this->em->remove($link);
        $this->em->flush();

        $this->addFlash('success', 'Link excluído com sucesso.');

        return $this->redirectToRoute('admin_waze_link_index');
    }

    // =========================================================================
    // HISTÓRICO
    // =========================================================================

    #[Route('/{id}/historico', name: 'historico', requirements: ['id' => '\\d+'])]
    public function historico(int $id): Response
    {
        $link = $this->em->find(RadarWazeLink::class, $id);

        if (!$link) {
            throw $this->createNotFoundException('Link não encontrado.');
        }

        $logs = $this->db->fetchAllAssociative(
            'SELECT l.campo_alterado, l.valor_anterior, l.valor_novo, l.changed_at, u.email AS changed_by
             FROM radar_waze_link_log l
             JOIN user u ON u.id = l.changed_by
             WHERE l.radar_waze_link_id = ?
             ORDER BY l.changed_at DESC',
            [$id]
        );

        return $this->render('admin/waze_link/historico.html.twig', [
            'link' => $link,
            'logs' => $logs,
        ]);
    }

    // =========================================================================
    // Validação
    // =========================================================================

    private function validateLink(string $url): array
    {
        $errors = [];

        if ($url === '') {
            $errors['waze_link'] = 'O link do Waze é obrigatório.';
            return $errors;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors['waze_link'] = 'Informe uma URL válida.';
        } elseif (RadarWazeLink::extractPermanentHazardId($url) === null) {
            $errors['waze_link'] = 'O link deve conter o parâmetro permanentHazards com valor numérico (ex: &permanentHazards=272464).';
        }

        return $errors;
    }
}
