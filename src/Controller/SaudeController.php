<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\AccessControlTrait;
use App\Entity\EstabelecimentoSaude;
use App\Entity\User;
use App\Service\SaudeReservaService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
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

    private const PER_PAGE = 50;
    private const COORD_SQL = '(e.latitude IS NOT NULL AND e.longitude IS NOT NULL AND e.latitude <> 0 AND e.longitude <> 0 AND e.latitude BETWEEN -90 AND 90 AND e.longitude BETWEEN -180 AND 180)';

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
    ) {}

    #[Route('', name: 'saude_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_SAUDE);
        $filters = [];
        foreach (['busca', 'uf', 'municipio', 'situacao', 'fluxo', 'link', 'coordenadas'] as $key) {
            $filters[$key] = trim($request->query->getString($key));
        }
        $filters['busca'] = $filters['busca'] ?: trim($request->query->getString('q'));
        $filters['uf'] = strtoupper($filters['uf']);
        // Compatibilidade com URLs dos filtros anteriores.
        if (in_array($filters['situacao'], ['com_link', 'sem_link'], true)) {
            $filters['link'] = $filters['link'] ?: $filters['situacao'];
            $filters['situacao'] = '';
        } elseif ($filters['situacao'] === 'sem_coord') {
            $filters['coordenadas'] = $filters['coordenadas'] ?: 'sem_coord';
            $filters['situacao'] = '';
        }
        foreach (['situacao' => ['ativo', 'inativo'], 'fluxo' => array_merge(['pendente'], EstabelecimentoSaude::ALL_STATUS), 'link' => ['com_link', 'sem_link'], 'coordenadas' => ['com_coord', 'sem_coord']] as $key => $valid) {
            if ($filters[$key] !== '' && !in_array($filters[$key], $valid, true)) $filters[$key] = '';
        }
        if ($filters['uf'] !== '') {
            if (!in_array($filters['uf'], User::ALL_UFS, true)) throw $this->createNotFoundException('UF inválida.');
            $this->requireUfAccess($filters['uf']);
        }
        $restriction = $this->enforceUfsOnQuery('e.uf');
        $where = [$restriction['clause'] ?: '1=1'];
        $params = $restriction['params'];
        if ($filters['busca'] !== '') {
            $where[] = '(e.nome_fantasia LIKE ? OR e.cnes LIKE ? OR e.municipio LIKE ? OR e.logradouro LIKE ?)';
            array_push($params, ...array_fill(0, 4, '%' . $filters['busca'] . '%'));
        }
        foreach (['uf', 'municipio'] as $key) {
            if ($filters[$key] !== '') {
                $where[] = $key === 'uf' ? 'e.uf = ?' : 'e.municipio LIKE ?';
                $params[] = $key === 'uf' ? $filters[$key] : '%' . $filters[$key] . '%';
            }
        }
        if ($filters['situacao'] !== '') $where[] = 'e.ativo = ' . ($filters['situacao'] === 'ativo' ? '1' : '0');
        if ($filters['link'] === 'sem_link') $where[] = "(e.link_waze IS NULL OR TRIM(e.link_waze) = '')";
        if ($filters['link'] === 'com_link') $where[] = "(e.link_waze IS NOT NULL AND TRIM(e.link_waze) <> '')";
        if ($filters['coordenadas'] === 'com_coord') $where[] = self::COORD_SQL;
        if ($filters['coordenadas'] === 'sem_coord') $where[] = 'NOT ' . self::COORD_SQL;
        if ($filters['fluxo'] === 'pendente') $where[] = 'e.status IS NULL';
        elseif ($filters['fluxo'] !== '') { $where[] = 'e.status = ?'; $params[] = $filters['fluxo']; }
        $sql = implode(' AND ', $where);
        $total = (int) $this->cache->get('saude_count_v2_' . hash('sha256', $sql . serialize($params)), function (ItemInterface $entry) use ($sql, $params): int {
            $entry->expiresAfter(60);
            return (int) $this->db->fetchOne("SELECT COUNT(*) FROM estabelecimento_saude e WHERE $sql", $params);
        });
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', $request->query->getInt('pagina', 1))));
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = $this->db->fetchAllAssociative("SELECT e.* FROM estabelecimento_saude e WHERE $sql ORDER BY e.nome_fantasia, e.id LIMIT " . self::PER_PAGE . " OFFSET $offset", $params);
        foreach ($rows as &$row) $row['has_coords'] = $this->coords($row['latitude'], $row['longitude']) !== null;
        unset($row);
        $allowedUfs = $this->allowedUfsForView();
        $ufs = $this->cache->get('saude_ufs_v2_' . hash('sha256', serialize($allowedUfs)), function (ItemInterface $entry) use ($allowedUfs): array {
            $entry->expiresAfter(3600);
            if ($allowedUfs === []) return [];
            $params = $allowedUfs ?? [];
            $sql = 'SELECT DISTINCT uf FROM estabelecimento_saude WHERE uf IS NOT NULL';
            if ($allowedUfs !== null) $sql .= ' AND uf IN (' . implode(',', array_fill(0, count($params), '?')) . ')';
            return array_column($this->db->fetchAllAssociative($sql . ' ORDER BY uf', $params), 'uf');
        });
        $stats = null;
        if (array_filter($filters, static fn(string $v): bool => $v !== '') === [] && $allowedUfs === null) {
            $stats = $this->cache->get('saude_stats_global', function (ItemInterface $entry): array {
                $entry->expiresAfter(300);
                return $this->db->fetchAssociative('SELECT COUNT(*) AS total, COALESCE(SUM(ativo = 1), 0) AS ativos, COUNT(DISTINCT uf) AS estados, COUNT(DISTINCT uf, municipio) AS municipios FROM estabelecimento_saude') ?: [];
            });
        }
        $request->getSession()->set('saude_filtros', $filters + ['page' => $page]);
        return $this->render('saude/index.html.twig', compact('rows', 'filters', 'total', 'pages', 'page', 'ufs', 'allowedUfs', 'stats') + ['perPage' => self::PER_PAGE]);
    }

    #[Route('/{id}', name: 'saude_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): Response
    {
        return $this->detail($this->load($id), $request);
    }

    #[Route('/{id}/link', name: 'saude_link_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function linkSave(int $id, Request $request): Response
    {
        $item = $this->load($id);
        $this->checkToken($request, 'saude_link_' . $id);
        $link = trim($request->request->getString('link_waze')) ?: null;
        if (!$this->validLink($link)) return $this->detail($item, $request, $link, ['Informe uma URL Waze válida com até 1.000 caracteres.'], 422);
        if ($link !== $item->getLinkWaze()) {
            $item->setLinkWaze($link)->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->cache->delete('saude_stats_global');
        }
        $this->addFlash('success', 'Link atualizado com sucesso.');
        return $this->redirectToRoute('saude_show', ['id' => $id]);
    }

    #[Route('/{id}/editar', name: 'saude_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(int $id, Request $request): Response
    {
        $item = $this->load($id);
        $values = $this->values($item);
        $revision = $this->revision($item);
        $errors = [];
        $responseCode = 200;
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'saude_edit_' . $id);
            foreach (array_keys($values) as $key) $values[$key] = trim($request->request->getString($key));
            foreach (['nomeFantasia' => 255, 'municipio' => 255, 'logradouro' => 500, 'telefone' => 50, 'linkWaze' => 1000] as $key => $max) {
                if (mb_strlen($values[$key]) > $max) $errors[] = $key . ': tamanho máximo ' . $max . '.';
            }
            if ($values['nomeFantasia'] === '') $errors[] = 'Informe o nome fantasia.';
            if (!in_array($values['ativo'], ['0', '1'], true)) $errors[] = 'Situação CNES inválida.';
            if (!$this->validLink($values['linkWaze'] ?: null)) $errors[] = 'Informe uma URL válida do Waze.';
            $bothEmpty = $values['latitude'] === '' && $values['longitude'] === '';
            if (!$bothEmpty && $this->coords($values['latitude'], $values['longitude']) === null) $errors[] = 'Informe ambas as coordenadas válidas e diferentes de zero, ou deixe ambas vazias.';
            if ($errors === []) {
                $saved = $this->em->wrapInTransaction(function () use ($item, $request, $values, $bothEmpty): bool {
                    $this->em->refresh($item, LockMode::PESSIMISTIC_WRITE);
                    if (!hash_equals($this->revision($item), $request->request->getString('revision'))) return false;
                    $item->setNomeFantasia($values['nomeFantasia'])->setMunicipio($values['municipio'] ?: null)
                        ->setLogradouro($values['logradouro'] ?: null)->setTelefone($values['telefone'] ?: null)
                        ->setLinkWaze($values['linkWaze'] ?: null)->setAtivo($values['ativo'] === '1')
                        ->setLatitude($bothEmpty ? null : sprintf('%.8F', (float) $values['latitude']))
                        ->setLongitude($bothEmpty ? null : sprintf('%.8F', (float) $values['longitude']))
                        ->setUpdatedAt(new \DateTimeImmutable());
                    return true;
                });
                if ($saved) {
                    $this->cache->delete('saude_stats_global');
                    $this->addFlash('success', 'Estabelecimento atualizado.');
                    return $this->redirectToRoute('saude_show', ['id' => $id]);
                }
                $errors[] = 'O registro mudou durante a edição. Reabra a página antes de salvar; seus valores estão abaixo para consulta.';
                $responseCode = 409;
            } else $responseCode = 422;
        }
        return $this->render('saude/edit.html.twig', compact('item', 'values', 'revision', 'errors'), new Response('', $responseCode));
    }

    private function load(int $id): EstabelecimentoSaude
    {
        $this->requirePermission(User::PERMISSION_SAUDE);
        $item = $this->em->find(EstabelecimentoSaude::class, $id);
        if (!$item instanceof EstabelecimentoSaude) throw $this->createNotFoundException('Estabelecimento não encontrado.');
        $this->requireUfAccess($item->getUf());
        return $item;
    }

    private function detail(EstabelecimentoSaude $item, Request $request, ?string $linkValue = null, array $errors = [], int $code = 200): Response
    {
        $coords = $this->coords($item->getLatitude(), $item->getLongitude());
        $reservationExpires = $item->getStatus() === EstabelecimentoSaude::STATUS_RESERVADO
            ? $item->getReservadoEm()?->modify('+' . SaudeReservaService::TTL_MINUTOS . ' minutes') : null;
        $reservationExpired = $item->getStatus() === EstabelecimentoSaude::STATUS_RESERVADO
            && ($reservationExpires === null || $reservationExpires < new \DateTimeImmutable());
        return $this->render('saude/show.html.twig', compact('item', 'coords', 'reservationExpires', 'reservationExpired', 'errors') + [
            'linkValue' => $errors === [] ? $item->getLinkWaze() : $linkValue,
            'voltarParams' => $request->getSession()->get('saude_filtros', []),
        ], new Response('', $code));
    }

    private function coords(mixed $lat, mixed $lon): ?array
    {
        if (!is_numeric($lat) || !is_numeric($lon)) return null;
        $lat = (float) $lat; $lon = (float) $lon;
        return is_finite($lat) && is_finite($lon) && abs($lat) <= 90 && abs($lon) <= 180 && $lat != 0 && $lon != 0 ? [$lat, $lon] : null;
    }

    private function validLink(?string $link): bool
    {
        if ($link === null) return true;
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));
        return mb_strlen($link) <= 1000 && filter_var($link, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($link, PHP_URL_SCHEME)), ['https', 'http'], true)
            && ($host === 'waze.com' || str_ends_with($host, '.waze.com'));
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) throw $this->createAccessDeniedException('Token inválido. Recarregue a página.');
    }

    private function values(EstabelecimentoSaude $item): array
    {
        return ['nomeFantasia' => $item->getNomeFantasia() ?? '', 'municipio' => $item->getMunicipio() ?? '',
            'logradouro' => $item->getLogradouro() ?? '', 'telefone' => $item->getTelefone() ?? '',
            'latitude' => $item->getLatitude() ?? '', 'longitude' => $item->getLongitude() ?? '',
            'linkWaze' => $item->getLinkWaze() ?? '', 'ativo' => $item->isAtivo() ? '1' : '0'];
    }

    private function revision(EstabelecimentoSaude $item): string
    {
        return hash('sha256', serialize([$this->values($item), $item->getCnes(), $item->getUf(), $item->getStatus(),
            $item->getReservadoPara(), $item->getReservadoEm()?->format('Y-m-d H:i:s.u'), $item->getRowHash(),
            $item->getUpdatedAt()?->format('Y-m-d H:i:s.u')]));
    }
}
