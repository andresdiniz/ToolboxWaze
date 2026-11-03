<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\AccessControlTrait;
use App\Entity\EstabelecimentoSaude;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/saude')]
#[IsGranted('ROLE_USER')]
class SaudeController extends AbstractController
{
    use AccessControlTrait;

    private const PER_PAGE    = 50;
    private const CACHE_TTL   = 3600; // 1 h — listas de filtros
    private const CACHE_STATS = 300;  // 5 min — stats e COUNT global
    private const CACHE_COUNT = 60;   // 1 min — COUNT com filtros ativos

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
    ) {
    }

    #[Route('', name: 'saude_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_SAUDE);

        $page      = max(1, (int) $request->query->get('page', 1));
        $busca     = trim((string) $request->query->get('busca', ''));
        $uf        = trim((string) $request->query->get('uf', ''));
        $municipio = trim((string) $request->query->get('municipio', ''));
        $situacao  = trim((string) $request->query->get('situacao', ''));
        $fluxo     = trim((string) $request->query->get('fluxo', ''));

        $request->getSession()->set('saude_filtros', [
            'busca'     => $busca,
            'uf'        => $uf,
            'municipio' => $municipio,
            'situacao'  => $situacao,
            'fluxo'     => $fluxo,
            'page'      => $page,
        ]);

        $where  = ['1=1'];
        $params = [];

        $ufRestriction = $this->enforceUfsOnQuery('e.uf');
        if ($ufRestriction['clause'] !== '') {
            $where[] = $ufRestriction['clause'];
            $params  = array_merge($params, $ufRestriction['params']);
        }

        if ($busca !== '') {
            $where[]  = '(e.nome_fantasia LIKE ? OR e.cnes LIKE ? OR e.municipio LIKE ? OR e.logradouro LIKE ?)';
            $params[] = "%$busca%";
            $params[] = "%$busca%";
            $params[] = "%$busca%";
            $params[] = "%$busca%";
        }
        if ($uf !== '') {
            $this->requireUfAccess($uf);
            $where[]  = 'e.uf = ?';
            $params[] = $uf;
        }
        if ($municipio !== '') {
            $where[]  = 'e.municipio LIKE ?';
            $params[] = "%$municipio%";
        }

        match ($situacao) {
            'ativo'    => ($where[] = 'e.ativo = 1'),
            'inativo'  => ($where[] = 'e.ativo = 0'),
            'sem_link' => ($where[] = "(e.link_waze IS NULL OR e.link_waze = '')"),
            'com_link' => ($where[] = "(e.link_waze IS NOT NULL AND e.link_waze != '')"),
            'sem_coord' => ($where[] = '(e.latitude IS NULL OR e.longitude IS NULL)'),
            default    => null,
        };

        if ($fluxo === 'pendente') {
            $where[] = 'e.status IS NULL';
        } elseif (in_array($fluxo, EstabelecimentoSaude::ALL_STATUS, true)) {
            $where[]  = 'e.status = ?';
            $params[] = $fluxo;
        }

        $whereClause = implode(' AND ', $where);
        $hasFilters  = ($busca !== '' || $uf !== '' || $municipio !== '' || $situacao !== '' || $fluxo !== '');

        $countCacheKey = $hasFilters
            ? 'saude_count_' . md5($whereClause . serialize($params))
            : 'saude_count_global';
        $countTtl = $hasFilters ? self::CACHE_COUNT : self::CACHE_STATS;

        $total = (int) $this->cache->get($countCacheKey, function (ItemInterface $item) use ($whereClause, $params, $countTtl): int {
            $item->expiresAfter($countTtl);

            return (int) $this->db->fetchOne("SELECT COUNT(*) FROM estabelecimento_saude e WHERE $whereClause", $params);
        });

        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = min($page, $pages);
        $offset = (int) (($page - 1) * self::PER_PAGE);
        $limit  = (int) self::PER_PAGE;

        $rows = $this->db->fetchAllAssociative(
            "SELECT e.id, e.cnes, e.nome_fantasia, e.ativo, e.uf, e.municipio, e.logradouro,
                    e.telefone, e.latitude, e.longitude, e.link_waze, e.status, e.reservado_para
             FROM estabelecimento_saude e
             WHERE $whereClause
             ORDER BY e.nome_fantasia
             LIMIT {$offset}, {$limit}",
            $params
        );

        $allowedUfs = $this->allowedUfsForView();

        $stats = null;
        if (!$hasFilters && $allowedUfs === null) {
            $stats = $this->cache->get('saude_stats_global', function (ItemInterface $item): array|false {
                $item->expiresAfter(self::CACHE_STATS);

                return $this->db->fetchAssociative(
                    'SELECT COUNT(*)                                  AS total,
                            COALESCE(SUM(e.ativo = 1), 0)             AS ativos,
                            COUNT(DISTINCT e.uf)                      AS estados,
                            COUNT(DISTINCT CONCAT(e.uf, e.municipio)) AS municipios
                     FROM estabelecimento_saude e'
                );
            }) ?: null;
        }

        $ufsCacheKey = $allowedUfs !== null
            ? 'saude_ufs_' . md5(implode(',', $allowedUfs))
            : 'saude_ufs_all';

        $ufs = $this->cache->get($ufsCacheKey, function (ItemInterface $item) use ($allowedUfs): array {
            $item->expiresAfter(self::CACHE_TTL);

            if ($allowedUfs !== null) {
                if ($allowedUfs === []) {
                    return [];
                }

                $ph = implode(',', array_fill(0, count($allowedUfs), '?'));

                return array_column($this->db->fetchAllAssociative(
                    "SELECT DISTINCT uf FROM estabelecimento_saude WHERE uf IS NOT NULL AND uf IN ($ph) ORDER BY uf",
                    $allowedUfs
                ), 'uf');
            }

            return array_column($this->db->fetchAllAssociative(
                'SELECT DISTINCT uf FROM estabelecimento_saude WHERE uf IS NOT NULL ORDER BY uf'
            ), 'uf');
        });

        return $this->render('saude/index.html.twig', [
            'rows'       => $rows,
            'page'       => $page,
            'pages'      => $pages,
            'total'      => $total,
            'perPage'    => self::PER_PAGE,
            'stats'      => $stats,
            'ufs'        => $ufs,
            'allowedUfs' => $allowedUfs,
            'filters'    => [
                'busca'     => $busca,
                'uf'        => $uf,
                'municipio' => $municipio,
                'situacao'  => $situacao,
                'fluxo'     => $fluxo,
            ],
        ]);
    }

    #[Route('/{id}', name: 'saude_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_SAUDE);

        $item = $this->em->find(EstabelecimentoSaude::class, $id);

        if (!$item instanceof EstabelecimentoSaude) {
            throw $this->createNotFoundException("Estabelecimento #{$id} não encontrado.");
        }

        $this->requireUfAccess($item->getUf());

        return $this->render('saude/show.html.twig', [
            'item'         => $item,
            'voltarParams' => $request->getSession()->get('saude_filtros', []),
        ]);
    }

    #[Route('/{id}/link', name: 'saude_link_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function linkSave(int $id, Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_SAUDE);

        $item = $this->em->find(EstabelecimentoSaude::class, $id);

        if (!$item instanceof EstabelecimentoSaude) {
            throw $this->createNotFoundException();
        }

        $this->requireUfAccess($item->getUf());

        $link = trim((string) $request->request->get('link_waze', '')) ?: null;

        if ($link !== null && (filter_var($link, FILTER_VALIDATE_URL) === false
            || !preg_match('/^https?:\/\/([a-z0-9-]+\.)?waze\.com\//i', $link))) {
            $this->addFlash('warning', 'O link deve ser uma URL válida do Waze.');

            return $this->redirectToRoute('saude_show', ['id' => $id]);
        }

        if ($link !== $item->getLinkWaze()) {
            $item->setLinkWaze($link);
            $item->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();

            $this->cache->delete('saude_stats_global');
            $this->cache->delete('saude_count_global');
        }

        $this->addFlash('success', 'Link atualizado com sucesso.');

        return $this->redirectToRoute('saude_show', ['id' => $id]);
    }
}
