<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/postos', name: 'posto_')]
final class PostoController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly Connection $db,
        private readonly Security $security,
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

        if ($allowedUfs !== null && count($allowedUfs) > 0) {
            $ph    = implode(',', array_fill(0, count($allowedUfs), '?'));
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

    // =========================================================================
    // SHOW
    // =========================================================================

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'])]
    public function show(int $id, Request $req): Response
    {
        /** @var User|null $user */
        $user  = $this->getUser();
        $posto = $this->db->fetchAssociative('SELECT * FROM fuel_reseller_raw WHERE id = ?', [$id]);

        if (!$posto) {
            throw $this->createNotFoundException('Posto n\u00e3o encontrado.');
        }

        if ($user && !$user->canAccessUf((string) ($posto['uf'] ?? ''))) {
            throw $this->createAccessDeniedException('Voc\u00ea n\u00e3o tem acesso a dados deste estado.');
        }

        if (is_string($posto['raw_data'] ?? null)) {
            $posto['raw_data'] = json_decode($posto['raw_data'], true) ?? [];
        }

        // Waze link atual
        $wazeLink = $this->db->fetchAssociative(
            'SELECT wl.*, ui.email AS inserted_by_email, uu.email AS updated_by_email
             FROM posto_waze_link wl
             JOIN user ui ON ui.id = wl.inserted_by
             LEFT JOIN user uu ON uu.id = wl.updated_by
             WHERE wl.posto_id = ?',
            [$id]
        ) ?: null;

        $wazeLog = [];
        if ($wazeLink) {
            $wazeLog = $this->db->fetchAllAssociative(
                'SELECT wll.*, u.email AS changed_by_email
                 FROM posto_waze_link_log wll
                 JOIN user u ON u.id = wll.changed_by
                 WHERE wll.posto_waze_link_id = ?
                 ORDER BY wll.changed_at DESC',
                [$wazeLink['id']]
            );
        }

        $session      = $req->getSession();
        $wazeErrors   = $session->remove('_posto_waze_errors_' . $id) ?? [];
        $wazeFormData = $session->remove('_posto_waze_form_'   . $id) ?? [];

        return $this->render('posto/show.html.twig', [
            'posto'        => $posto,
            'wazeLink'     => $wazeLink,
            'wazeLog'      => $wazeLog,
            'wazeErrors'   => $wazeErrors,
            'wazeFormData' => $wazeFormData,
        ]);
    }

    // =========================================================================
    // WAZE SAVE
    // =========================================================================

    #[Route('/{id}/waze-salvar', name: 'waze_save', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function wazeSave(int $id, Request $req): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        if (!$this->isCsrfTokenValid('posto_waze_save_' . $id, $req->request->get('_token'))) {
            $this->addFlash('error', 'Token de seguran\u00e7a inv\u00e1lido.');
            return $this->redirectToRoute('posto_show', ['id' => $id]);
        }

        $posto = $this->db->fetchAssociative('SELECT id, uf FROM fuel_reseller_raw WHERE id = ?', [$id]);
        if (!$posto) {
            throw $this->createNotFoundException('Posto n\u00e3o encontrado.');
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$user->canAccessUf((string) ($posto['uf'] ?? ''))) {
            throw $this->createAccessDeniedException('Acesso negado a este estado.');
        }

        $userId = $user->getId();
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $wazeLink      = trim((string) $req->request->get('waze_link', ''));
        $motivoRevisao = trim((string) $req->request->get('motivo_revisao', ''));

        $errors = [];

        if ($wazeLink === '') {
            $errors['waze_link'] = 'O link do Waze \u00e9 obrigat\u00f3rio.';
        } elseif (!filter_var($wazeLink, FILTER_VALIDATE_URL)) {
            $errors['waze_link'] = 'Informe uma URL v\u00e1lida.';
        } elseif (!preg_match('/[?&]venues=(\d+)/', $wazeLink, $m)) {
            $errors['waze_link'] = 'A URL deve conter o par\u00e2metro venues=N\u00daMERO.';
        }

        $existing = $this->db->fetchAssociative(
            'SELECT * FROM posto_waze_link WHERE posto_id = ?', [$id]
        ) ?: null;

        if ($existing && $motivoRevisao === '') {
            $errors['motivo_revisao'] = 'Informe o motivo da revis\u00e3o.';
        }

        if ($errors !== []) {
            $req->getSession()->set('_posto_waze_errors_' . $id, $errors);
            $req->getSession()->set('_posto_waze_form_'   . $id, [
                'waze_link'      => $wazeLink,
                'motivo_revisao' => $motivoRevisao,
            ]);
            return $this->redirectToRoute('posto_show', ['id' => $id, '_fragment' => 'waze-form-collapse']);
        }

        // O parâmetro da URL é venues= mas a coluna no banco é permanent_hazard_id
        $hazardId = (int) $m[1];

        if ($existing) {
            if ($wazeLink !== $existing['waze_link']) {
                $this->db->executeStatement(
                    'INSERT INTO posto_waze_link_log
                     (posto_waze_link_id, changed_by, campo_alterado, valor_anterior, valor_novo, changed_at)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$existing['id'], $userId, 'waze_link', $existing['waze_link'], $wazeLink, $now]
                );
            }

            if (($existing['observacao'] ?? '') !== $motivoRevisao) {
                $this->db->executeStatement(
                    'INSERT INTO posto_waze_link_log
                     (posto_waze_link_id, changed_by, campo_alterado, valor_anterior, valor_novo, changed_at)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$existing['id'], $userId, 'motivo_revisao', $existing['observacao'] ?? null, $motivoRevisao, $now]
                );
            }

            $this->db->executeStatement(
                'UPDATE posto_waze_link
                 SET waze_link = ?, permanent_hazard_id = ?, observacao = ?, updated_by = ?, updated_at = ?
                 WHERE id = ?',
                [$wazeLink, $hazardId, $motivoRevisao ?: null, $userId, $now, $existing['id']]
            );

            $this->addFlash('success', 'Link Waze atualizado com sucesso.');
        } else {
            $this->db->executeStatement(
                'INSERT INTO posto_waze_link
                 (posto_id, waze_link, permanent_hazard_id, observacao, inserted_by, inserted_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $wazeLink, $hazardId, $motivoRevisao ?: null, $userId, $now]
            );

            $newId = (int) $this->db->lastInsertId();

            $this->db->executeStatement(
                'INSERT INTO posto_waze_link_log
                 (posto_waze_link_id, changed_by, campo_alterado, valor_anterior, valor_novo, changed_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$newId, $userId, 'waze_link', null, $wazeLink, $now]
            );

            $this->addFlash('success', 'Link Waze cadastrado com sucesso.');
        }

        return $this->redirectToRoute('posto_show', ['id' => $id]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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
