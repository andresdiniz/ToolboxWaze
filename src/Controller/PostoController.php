<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/postos', name: 'posto_')]
final class PostoController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly Connection $db,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $req): Response
    {
        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $uf        = strtoupper(trim((string) $req->query->get('uf', '')));
        $municipio = trim((string) $req->query->get('municipio', ''));
        $bandeira  = trim((string) $req->query->get('bandeira', ''));
        $busca     = trim((string) $req->query->get('busca', ''));
        $page      = max(1, (int) $req->query->get('page', 1));
        $offset    = ($page - 1) * self::PER_PAGE;

        // Bloqueia filtro manual de UF não permitida
        if ($uf !== '' && $allowedUfs !== null && !in_array($uf, $allowedUfs, true)) {
            $uf = '';
        }

        [$where, $params] = $this->buildWhere($uf, $municipio, $bandeira, $busca, $allowedUfs);
        $whereClause = $where ? " WHERE $where" : '';

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM fuel_reseller_raw$whereClause", $params
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, razao_social, nome_fantasia, cnpj, bandeira,
                    endereco, complemento, bairro, cep, municipio, uf,
                    autorizacao, data_publicacao, data_vinculacao
             FROM fuel_reseller_raw
             $whereClause
             ORDER BY uf, municipio, razao_social
             LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );

        // UFs do filtro = apenas as permitidas ao usuário
        $ufsQuery  = 'SELECT DISTINCT uf FROM fuel_reseller_raw WHERE uf IS NOT NULL';
        $ufsParams = [];
        if ($allowedUfs !== null && count($allowedUfs) > 0) {
            $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
            $ufsQuery .= " AND uf IN ($ph)";
            $ufsParams = $allowedUfs;
        } elseif ($allowedUfs !== null && count($allowedUfs) === 0) {
            $ufsQuery .= ' AND 1=0';
        }
        $ufsQuery .= ' ORDER BY uf';
        $ufs = array_column($this->db->fetchAllAssociative($ufsQuery, $ufsParams), 'uf');

        $bandeiras = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT bandeira FROM fuel_reseller_raw WHERE bandeira IS NOT NULL ORDER BY bandeira'
        ), 'bandeira');

        // Stats filtradas
        if ($allowedUfs !== null && count($allowedUfs) > 0) {
            $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
            $stats = $this->db->fetchAssociative(
                "SELECT COUNT(*) AS total,
                        COUNT(DISTINCT uf) AS estados,
                        COUNT(DISTINCT municipio) AS municipios,
                        COUNT(DISTINCT bandeira) AS bandeiras
                 FROM fuel_reseller_raw WHERE uf IN ($ph)",
                $allowedUfs
            );
        } elseif ($allowedUfs !== null && count($allowedUfs) === 0) {
            $stats = ['total' => 0, 'estados' => 0, 'municipios' => 0, 'bandeiras' => 0];
        } else {
            $stats = $this->db->fetchAssociative(
                'SELECT COUNT(*) AS total, COUNT(DISTINCT uf) AS estados,
                        COUNT(DISTINCT municipio) AS municipios, COUNT(DISTINCT bandeira) AS bandeiras
                 FROM fuel_reseller_raw'
            );
        }

        return $this->render('posto/index.html.twig', [
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => self::PER_PAGE,
            'pages'      => (int) ceil($total / self::PER_PAGE),
            'filters'    => compact('uf', 'municipio', 'bandeira', 'busca'),
            'ufs'        => $ufs,
            'bandeiras'  => $bandeiras,
            'stats'      => $stats,
            'allowedUfs' => $allowedUfs,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'])]
    public function show(int $id): Response
    {
        /** @var User|null $user */
        $user  = $this->getUser();
        $posto = $this->db->fetchAssociative('SELECT * FROM fuel_reseller_raw WHERE id = ?', [$id]);

        if (!$posto) {
            throw $this->createNotFoundException('Posto não encontrado.');
        }

        if ($user && !$user->canAccessUf((string) ($posto['uf'] ?? ''))) {
            throw $this->createAccessDeniedException('Você não tem acesso a dados deste estado.');
        }

        if (is_string($posto['raw_data'])) {
            $posto['raw_data'] = json_decode($posto['raw_data'], true) ?? [];
        }

        return $this->render('posto/show.html.twig', ['posto' => $posto]);
    }

    // -------------------------------------------------------------------------

    private function buildWhere(
        string $uf,
        string $municipio,
        string $bandeira,
        string $busca,
        ?array $allowedUfs,
    ): array {
        $parts  = [];
        $params = [];

        if ($allowedUfs !== null) {
            if (count($allowedUfs) === 0) {
                $parts[] = '1=0';
            } else {
                $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
                $parts[] = "uf IN ($ph)";
                foreach ($allowedUfs as $v) {
                    $params[] = $v;
                }
            }
        }

        if ($uf !== '') {
            $parts[]  = 'uf = ?';
            $params[] = $uf;
        }
        if ($municipio !== '') {
            $parts[]  = 'municipio LIKE ?';
            $params[] = "%$municipio%";
        }
        if ($bandeira !== '') {
            $parts[]  = 'bandeira = ?';
            $params[] = $bandeira;
        }
        if ($busca !== '') {
            $parts[]  = '(razao_social LIKE ? OR nome_fantasia LIKE ? OR cnpj LIKE ?)';
            $params[] = "%$busca%";
            $params[] = "%$busca%";
            $params[] = "%$busca%";
        }

        return [implode(' AND ', $parts), $params];
    }
}
