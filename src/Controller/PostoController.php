<?php

namespace App\Controller;

use App\Entity\PostoWazeLink;
use App\Entity\PostoWazeLinkLog;
use App\Repository\FuelResellerRawRepository;
use App\Repository\PostoWazeLinkRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/postos')]
#[IsGranted('ROLE_USER')]
class PostoController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly Connection                 $db,
        private readonly FuelResellerRawRepository  $postoRepo,
        private readonly PostoWazeLinkRepository    $linkRepo,
    ) {}

    #[Route('', name: 'posto_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page   = max(1, (int) $request->query->get('page', 1));
        $search = trim((string) $request->query->get('q', ''));
        $uf     = trim((string) $request->query->get('uf', ''));
        $offset = ($page - 1) * self::PER_PAGE;

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[]  = '(r.nome_fantasia LIKE ? OR r.razao_social LIKE ? OR r.cnpj LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if ($uf !== '') {
            $where[]  = 'r.estado = ?';
            $params[] = $uf;
        }

        $whereClause = implode(' AND ', $where);

        // DBAL 3.x/4.x: sem terceiro argumento de tipos (PDO::PARAM_* não é aceito)
        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM fuel_reseller_raw r WHERE $whereClause",
            $params
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT r.id, r.nome_fantasia, r.razao_social, r.cnpj, r.estado, r.municipio,
                    pwl.id AS link_id, pwl.waze_link, pwl.permanent_hazard_id
             FROM fuel_reseller_raw r
             LEFT JOIN posto_waze_link pwl ON pwl.posto_id = r.id
             WHERE $whereClause
             ORDER BY r.nome_fantasia
             LIMIT $offset, " . self::PER_PAGE,
            $params
        );

        return $this->render('posto/index.html.twig', [
            'rows'    => $rows,
            'page'    => $page,
            'total'   => $total,
            'perPage' => self::PER_PAGE,
            'search'  => $search,
            'uf'      => $uf,
        ]);
    }

    #[Route('/{id}', name: 'posto_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(int $id): Response
    {
        $posto = $this->postoRepo->find($id)
            ?? throw $this->createNotFoundException("Posto #$id não encontrado.");

        $link = $this->linkRepo->findOneBy(['posto' => $posto]);

        return $this->render('posto/show.html.twig', [
            'posto' => $posto,
            'link'  => $link,
        ]);
    }

    #[Route('/{id}/waze-save', name: 'posto_waze_save', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function wazeSave(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('waze_posto_' . $id, $request->request->get('_token'))) {
            throw new AccessDeniedException('Token CSRF inválido.');
        }

        $posto = $this->postoRepo->find($id)
            ?? throw $this->createNotFoundException("Posto #$id não encontrado.");

        $wazeLink    = trim((string) $request->request->get('waze_link', ''));
        $observacao  = trim((string) $request->request->get('observacao', '')) ?: null;
        $user        = $this->getUser();
        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $hazardId = PostoWazeLink::extractVenueId($wazeLink);

        if ($hazardId === null) {
            $this->addFlash('danger', 'O link deve conter o parâmetro venues com valor numérico (ex: &venues=207160888).');
            return $this->redirectToRoute('posto_show', [
                'id'        => $id,
                '_fragment' => 'waze-form-collapse',
            ]);
        }

        $existing = $this->db->fetchAssociative(
            'SELECT id, waze_link FROM posto_waze_link WHERE posto_id = ?',
            [$id]
        );

        if ($existing === false) {
            $this->db->executeStatement(
                'INSERT INTO posto_waze_link (posto_id, waze_link, permanent_hazard_id, observacao, inserted_by, inserted_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $wazeLink, $hazardId, $observacao, $user->getId(), $now]
            );
        } else {
            $this->db->executeStatement(
                'INSERT INTO posto_waze_link_log (posto_waze_link_id, waze_link_anterior, changed_by, changed_at)
                 VALUES (?, ?, ?, ?)',
                [$existing['id'], $existing['waze_link'], $user->getId(), $now]
            );

            $this->db->executeStatement(
                'UPDATE posto_waze_link SET waze_link = ?, permanent_hazard_id = ?, observacao = ?, updated_by = ?, updated_at = ?
                 WHERE posto_id = ?',
                [$wazeLink, $hazardId, $observacao, $user->getId(), $now, $id]
            );
        }

        $this->addFlash('success', 'Link Waze salvo com sucesso.');

        return $this->redirectToRoute('posto_show', [
            'id'        => $id,
            '_fragment' => 'waze-form-collapse',
        ]);
    }

    #[Route('/{id}/waze-delete', name: 'posto_waze_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function wazeDelete(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('waze_posto_delete_' . $id, $request->request->get('_token'))) {
            throw new AccessDeniedException('Token CSRF inválido.');
        }

        $this->db->executeStatement(
            'DELETE FROM posto_waze_link WHERE posto_id = ?',
            [$id]
        );

        $this->addFlash('success', 'Link Waze removido.');

        return $this->redirectToRoute('posto_show', ['id' => $id]);
    }

    #[Route('/{id}/waze-suggest', name: 'posto_waze_suggest', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function wazeSuggest(int $id): JsonResponse
    {
        $posto = $this->postoRepo->find($id)
            ?? throw $this->createNotFoundException();

        $lat = $posto->getLatitude();
        $lon = $posto->getLongitude();

        if ($lat === null || $lon === null) {
            return $this->json(['error' => 'Posto sem coordenadas.'], 422);
        }

        $url = sprintf(
            'https://www.waze.com/pt-BR/editor?env=row&lat=%s&lon=%s&marker=true&zoomLevel=17',
            $lat,
            $lon,
        );

        return $this->json(['url' => $url]);
    }
}
