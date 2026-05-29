<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\AccessControlTrait;
use App\Entity\PostoWazeLink;
use App\Entity\User;
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
    use AccessControlTrait;

    private const PER_PAGE = 50;

    public function __construct(
        private readonly Connection                $db,
        private readonly FuelResellerRawRepository $postoRepo,
        private readonly PostoWazeLinkRepository   $linkRepo,
    ) {}

    #[Route('', name: 'posto_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_POSTOS);

        $page      = max(1, (int) $request->query->get('page', 1));
        $busca     = trim((string) $request->query->get('busca', ''));
        $uf        = trim((string) $request->query->get('uf', ''));
        $municipio = trim((string) $request->query->get('municipio', ''));
        $bandeira  = trim((string) $request->query->get('bandeira', ''));
        $offset    = ($page - 1) * self::PER_PAGE;

        $where  = ['1=1'];
        $params = [];

        $ufRestriction = $this->enforceUfsOnQuery('r.uf');
        if ($ufRestriction['clause'] !== '') {
            $where[]  = $ufRestriction['clause'];
            $params   = array_merge($params, $ufRestriction['params']);
        }

        if ($busca !== '') {
            $where[]  = '(r.nome_fantasia LIKE ? OR r.razao_social LIKE ? OR r.cnpj LIKE ?)';
            $params[] = "%$busca%";
            $params[] = "%$busca%";
            $params[] = "%$busca%";
        }
        if ($uf !== '') {
            $this->requireUfAccess($uf);
            $where[]  = 'r.uf = ?';
            $params[] = $uf;
        }
        if ($municipio !== '') {
            $where[]  = 'r.municipio LIKE ?';
            $params[] = "%$municipio%";
        }
        if ($bandeira !== '') {
            $where[]  = 'r.bandeira = ?';
            $params[] = $bandeira;
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM fuel_reseller_raw r WHERE $whereClause",
            $params
        );
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $this->db->fetchAllAssociative(
            "SELECT r.id, r.nome_fantasia, r.razao_social, r.cnpj,
                    r.uf, r.municipio, r.bandeira,
                    r.endereco, r.complemento, r.autorizacao,
                    pwl.id AS link_id, pwl.waze_link, pwl.venue_id
             FROM fuel_reseller_raw r
             LEFT JOIN posto_waze_link pwl ON pwl.posto_id = r.id
             WHERE $whereClause
             ORDER BY r.nome_fantasia
             LIMIT $offset, " . self::PER_PAGE,
            $params
        );

        $allowedUfs = $this->allowedUfsForView();

        $stats = null;
        if ($busca === '' && $uf === '' && $municipio === '' && $bandeira === '' && $allowedUfs === null) {
            $stats = $this->db->fetchAssociative(
                "SELECT COUNT(*)                      AS total,
                        COUNT(DISTINCT r.uf)           AS estados,
                        COUNT(DISTINCT r.municipio)    AS municipios,
                        COUNT(DISTINCT r.bandeira)     AS bandeiras
                 FROM fuel_reseller_raw r"
            ) ?: null;
        }

        $ufsQuery = $allowedUfs !== null
            ? 'SELECT DISTINCT uf FROM fuel_reseller_raw WHERE uf IS NOT NULL AND uf IN (?' . str_repeat(',?', count($allowedUfs) - 1) . ') ORDER BY uf'
            : 'SELECT DISTINCT uf FROM fuel_reseller_raw WHERE uf IS NOT NULL ORDER BY uf';
        $ufs = array_column(
            $this->db->fetchAllAssociative($ufsQuery, $allowedUfs ?? []),
            'uf'
        );

        $bandeiras = array_column(
            $this->db->fetchAllAssociative(
                "SELECT DISTINCT bandeira FROM fuel_reseller_raw WHERE bandeira IS NOT NULL ORDER BY bandeira"
            ),
            'bandeira'
        );

        return $this->render('posto/index.html.twig', [
            'rows'       => $rows,
            'page'       => $page,
            'pages'      => $pages,
            'total'      => $total,
            'perPage'    => self::PER_PAGE,
            'stats'      => $stats,
            'ufs'        => $ufs,
            'bandeiras'  => $bandeiras,
            'allowedUfs' => $allowedUfs,
            'filters'    => [
                'busca'     => $busca,
                'uf'        => $uf,
                'municipio' => $municipio,
                'bandeira'  => $bandeira,
            ],
        ]);
    }

    #[Route('/{id}', name: 'posto_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(int $id): Response
    {
        $this->requirePermission(User::PERMISSION_POSTOS);

        $posto = $this->postoRepo->find($id)
            ?? throw $this->createNotFoundException("Posto #$id não encontrado.");

        $this->requireUfAccess($posto->getUf());

        $wazeLink = $this->linkRepo->findOneBy(['posto' => $posto]);
        $wazeLog  = $wazeLink ? $wazeLink->getLogs()->toArray() : [];

        return $this->render('posto/show.html.twig', [
            'posto'        => $posto,
            'wazeLink'     => $wazeLink,
            'wazeLog'      => $wazeLog,
            'wazeErrors'   => [],
            'wazeFormData' => [],
        ]);
    }

    #[Route('/{id}/waze-save', name: 'posto_waze_save', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function wazeSave(int $id, Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_POSTOS);

        if (!$this->isCsrfTokenValid('posto_waze_save_' . $id, $request->request->get('_token'))) {
            throw new AccessDeniedException('Token CSRF inválido.');
        }

        $posto = $this->postoRepo->find($id)
            ?? throw $this->createNotFoundException("Posto #$id não encontrado.");

        $this->requireUfAccess($posto->getUf());

        $wazeUrl    = trim((string) $request->request->get('waze_link', ''));
        $observacao = trim((string) $request->request->get('observacao', '')) ?: null;
        $user       = $this->getUser();
        $now        = new \DateTimeImmutable();

        $venueId = PostoWazeLink::extractVenueId($wazeUrl);

        if ($venueId === null) {
            $this->addFlash('danger', 'O link deve conter o parâmetro venues com valor numérico (ex: &venues=207160888).');
            return $this->redirectToRoute('posto_show', [
                'id'        => $id,
                '_fragment' => 'waze-form-collapse',
            ]);
        }

        $existing = $this->linkRepo->findOneBy(['posto' => $posto]);

        if ($existing === null) {
            $link = new PostoWazeLink();
            $link->setPosto($posto);
            $link->setWazeLink($wazeUrl);
            $link->setInsertedBy($user);
            $link->setInsertedAt($now);
            $link->setObservacao($observacao);
            $this->linkRepo->getEntityManager()->persist($link);
        } else {
            $existing->setWazeLink($wazeUrl);
            $existing->setUpdatedBy($user);
            $existing->setUpdatedAt($now);
            $existing->setObservacao($observacao);
        }

        $this->linkRepo->getEntityManager()->flush();

        $this->addFlash('success', 'Link Waze salvo com sucesso.');

        return $this->redirectToRoute('posto_show', [
            'id'        => $id,
            '_fragment' => 'waze-form-collapse',
        ]);
    }

    #[Route('/{id}/waze-delete', name: 'posto_waze_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function wazeDelete(int $id, Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_POSTOS);

        if (!$this->isCsrfTokenValid('waze_posto_delete_' . $id, $request->request->get('_token'))) {
            throw new AccessDeniedException('Token CSRF inválido.');
        }

        $posto = $this->postoRepo->find($id)
            ?? throw $this->createNotFoundException("Posto #$id não encontrado.");

        $this->requireUfAccess($posto->getUf());

        $link = $this->linkRepo->findOneBy(['posto' => $posto]);

        if ($link !== null) {
            $this->linkRepo->getEntityManager()->remove($link);
            $this->linkRepo->getEntityManager()->flush();
        }

        $this->addFlash('success', 'Link Waze removido.');

        return $this->redirectToRoute('posto_show', ['id' => $id]);
    }

    #[Route('/{id}/waze-suggest', name: 'posto_waze_suggest', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function wazeSuggest(int $id): JsonResponse
    {
        $this->requirePermission(User::PERMISSION_POSTOS);

        $posto = $this->postoRepo->find($id)
            ?? throw $this->createNotFoundException();

        $this->requireUfAccess($posto->getUf());

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
