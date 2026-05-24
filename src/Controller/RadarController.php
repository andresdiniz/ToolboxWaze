<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/radares', name: 'radar_')]
final class RadarController extends AbstractController
{
    private const PER_PAGE = 25;

    /**
     * data_validade e data_verificacao_efetiva estão armazenadas no banco
     * como string 'd/m/Y' (ex: '03/11/2026').
     * Para comparações de data, sempre converter com STR_TO_DATE(col, '%d/%m/%Y').
     */
    private const DATE_CONV = "STR_TO_DATE(%s, '%%d/%%m/%%Y')";

    public function __construct(
        private readonly Connection $db,
    ) {}

    private function dateConv(string $col): string
    {
        return sprintf(self::DATE_CONV, $col);
    }

    #[Route('', name: 'index')]
    public function index(Request $req): Response
    {
        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $uf        = strtoupper(trim((string) $req->query->get('uf', '')));
        $municipio = trim((string) $req->query->get('municipio', ''));
        $resultado = trim((string) $req->query->get('resultado', ''));
        $tipo      = trim((string) $req->query->get('tipo', ''));
        $validade  = trim((string) $req->query->get('validade', ''));
        $serie     = trim((string) $req->query->get('serie', ''));
        $page      = max(1, (int) $req->query->get('page', 1));
        $offset    = ($page - 1) * self::PER_PAGE;

        if ($uf !== '' && $allowedUfs !== null && !in_array($uf, $allowedUfs, true)) {
            $uf = '';
        }

        [$where, $params] = $this->buildWhere($uf, $municipio, $resultado, $tipo, $validade, $serie, $allowedUfs);

        $baseFrom    = $this->buildFrom($serie);
        $whereClause = $where ? " WHERE $where" : '';

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT rm.id) FROM radar_medidor rm $baseFrom $whereClause",
            $params
        );

        $dv = $this->dateConv('rm.data_validade');

        // OFFSET e LIMIT passados como parâmetros para evitar interpolação direta
        $rowParams   = array_merge($params, [$offset, self::PER_PAGE]);
        $rows = $this->db->fetchAllAssociative(
            "SELECT DISTINCT rm.id, rm.sigla_uf, rm.estado, rm.municipio,
                    rm.local_verificacao,
                    rm.data_ultima_verificacao,
                    rm.data_verificacao_efetiva,
                    rm.data_validade,
                    DATE_FORMAT($dv, '%Y-%m-%d') AS data_validade_iso,
                    rm.ultimo_resultado,
                    rm.tipo_medidor, rm.proprietario_nome
             FROM radar_medidor rm $baseFrom
             $whereClause
             ORDER BY rm.sigla_uf, rm.municipio, rm.local_verificacao
             LIMIT ?, ?",
            $rowParams
        );

        $ufsQuery  = 'SELECT DISTINCT sigla_uf FROM radar_medidor WHERE sigla_uf IS NOT NULL';
        $ufsParams = [];
        if ($allowedUfs !== null && count($allowedUfs) > 0) {
            $placeholders = implode(',', array_fill(0, count($allowedUfs), '?'));
            $ufsQuery    .= " AND sigla_uf IN ($placeholders)";
            $ufsParams    = $allowedUfs;
        } elseif ($allowedUfs !== null && count($allowedUfs) === 0) {
            $ufsQuery .= ' AND 1=0';
        }
        $ufsQuery .= ' ORDER BY sigla_uf';
        $ufs = array_column($this->db->fetchAllAssociative($ufsQuery, $ufsParams), 'sigla_uf');

        $resultados = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT ultimo_resultado FROM radar_medidor WHERE ultimo_resultado IS NOT NULL ORDER BY ultimo_resultado'
        ), 'ultimo_resultado');

        $tipos = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT tipo_medidor FROM radar_medidor WHERE tipo_medidor IS NOT NULL ORDER BY tipo_medidor'
        ), 'tipo_medidor');

        $hoje        = (new \DateTimeImmutable())->format('Y-m-d');
        $em30        = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $ha30dias    = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        // ── Stats ─────────────────────────────────────────────────────────────
        $dv = $this->dateConv('data_validade');
        $statsSql = "SELECT COUNT(*) AS total,
                            SUM(ultimo_resultado = 'APROVADO')  AS aprovados,
                            SUM(ultimo_resultado = 'REPROVADO') AS reprovados,
                            SUM(data_validade IS NOT NULL AND $dv < ?)                       AS vencidos,
                            SUM(data_validade IS NOT NULL AND $dv >= ? AND $dv <= ?)         AS vencendo,
                            COUNT(DISTINCT sigla_uf) AS estados
                     FROM radar_medidor";

        if ($allowedUfs !== null && count($allowedUfs) > 0) {
            $ph     = implode(',', array_fill(0, count($allowedUfs), '?'));
            $stats  = $this->db->fetchAssociative(
                $statsSql . " WHERE sigla_uf IN ($ph)",
                array_merge([$hoje, $hoje, $em30], $allowedUfs)
            );
        } elseif ($allowedUfs !== null && count($allowedUfs) === 0) {
            $stats = ['total' => 0, 'aprovados' => 0, 'reprovados' => 0, 'vencidos' => 0, 'vencendo' => 0, 'estados' => 0];
        } else {
            $stats = $this->db->fetchAssociative(
                $statsSql,
                [$hoje, $hoje, $em30]
            );
        }

        return $this->render('radar/index.html.twig', [
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => self::PER_PAGE,
            'pages'      => (int) ceil($total / self::PER_PAGE),
            'filters'    => compact('uf', 'municipio', 'resultado', 'tipo', 'validade', 'serie'),
            'ufs'        => $ufs,
            'resultados' => $resultados,
            'tipos'      => $tipos,
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
        $user = $this->getUser();

        $dv    = $this->dateConv('data_validade');
        $radar = $this->db->fetchAssociative(
            "SELECT *, DATE_FORMAT($dv, '%Y-%m-%d') AS data_validade_iso
             FROM radar_medidor WHERE id = ?",
            [$id]
        );

        if (!$radar) {
            throw $this->createNotFoundException('Radar não encontrado.');
        }

        if ($user && !$user->canAccessUf((string) ($radar['sigla_uf'] ?? ''))) {
            throw $this->createAccessDeniedException('Você não tem acesso a dados deste estado.');
        }

        $faixas    = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_faixa WHERE radar_medidor_id = ? ORDER BY numero_faixa', [$id]
        );
        $historico = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_historico WHERE radar_medidor_id = ? ORDER BY ano DESC, data_laudo DESC', [$id]
        );

        $wazeLink = $this->db->fetchAssociative(
            'SELECT wl.*, ui.email AS inserted_by_email, uu.email AS updated_by_email
             FROM radar_waze_link wl
             JOIN user ui ON ui.id = wl.inserted_by
             LEFT JOIN user uu ON uu.id = wl.updated_by
             WHERE wl.radar_medidor_id = ?',
            [$id]
        ) ?: null;

        $wazeLog = [];
        if ($wazeLink) {
            $wazeLog = $this->db->fetchAllAssociative(
                'SELECT wll.*, u.email AS changed_by_email
                 FROM radar_waze_link_log wll
                 JOIN user u ON u.id = wll.changed_by
                 WHERE wll.radar_waze_link_id = ?
                 ORDER BY wll.changed_at DESC',
                [$wazeLink['id']]
            );
        }

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

        $radar = $this->db->fetchAssociative('SELECT id, sigla_uf FROM radar_medidor WHERE id = ?', [$id]);
        if (!$radar) {
            throw $this->createNotFoundException('Radar não encontrado.');
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$user->canAccessUf((string) ($radar['sigla_uf'] ?? ''))) {
            throw $this->createAccessDeniedException('Você não tem acesso a dados deste estado.');
        }

        $userId = $user->getId();
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $wazeLink      = trim((string) $req->request->get('waze_link', ''));
        $motivoRevisao = trim((string) $req->request->get('motivo_revisao', ''));

        $errors = [];

        if ($wazeLink === '') {
            $errors['waze_link'] = 'O link do Waze é obrigatório.';
        } elseif (!filter_var($wazeLink, FILTER_VALIDATE_URL)) {
            $errors['waze_link'] = 'Informe uma URL válida.';
        } elseif (!preg_match('/[?&]permanentHazards=(\d+)/', $wazeLink, $m)) {
            $errors['waze_link'] = 'A URL deve conter o parâmetro permanentHazards=NÚMERO.';
        }

        $existing = $this->db->fetchAssociative(
            'SELECT * FROM radar_waze_link WHERE radar_medidor_id = ?', [$id]
        ) ?: null;

        if ($existing && $motivoRevisao === '') {
            $errors['motivo_revisao'] = 'Informe o motivo da revisão.';
        }

        if ($errors !== []) {
            $req->getSession()->set('_waze_errors_' . $id, $errors);
            $req->getSession()->set('_waze_form_'   . $id, [
                'waze_link'      => $wazeLink,
                'motivo_revisao' => $motivoRevisao,
            ]);
            return $this->redirectToRoute('radar_show', ['id' => $id, '_fragment' => 'waze-form-collapse']);
        }

        $hazardId = (int) $m[1];

        if ($existing) {
            if ($wazeLink !== $existing['waze_link']) {
                $this->db->executeStatement(
                    'INSERT INTO radar_waze_link_log
                     (radar_waze_link_id, changed_by, campo_alterado, valor_anterior, valor_novo, changed_at)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$existing['id'], $userId, 'waze_link', $existing['waze_link'], $wazeLink, $now]
                );
            }

            if (($existing['observacao'] ?? '') !== $motivoRevisao) {
                $this->db->executeStatement(
                    'INSERT INTO radar_waze_link_log
                     (radar_waze_link_id, changed_by, campo_alterado, valor_anterior, valor_novo, changed_at)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$existing['id'], $userId, 'motivo_revisao', $existing['observacao'] ?? null, $motivoRevisao, $now]
                );
            }

            $this->db->executeStatement(
                'UPDATE radar_waze_link
                 SET waze_link = ?, permanent_hazard_id = ?, observacao = ?, updated_by = ?, updated_at = ?
                 WHERE id = ?',
                [$wazeLink, $hazardId, $motivoRevisao ?: null, $userId, $now, $existing['id']]
            );

            $this->addFlash('success', 'Link Waze atualizado com sucesso.');
        } else {
            $this->db->executeStatement(
                'INSERT INTO radar_waze_link
                 (radar_medidor_id, waze_link, permanent_hazard_id, observacao, inserted_by, inserted_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $wazeLink, $hazardId, $motivoRevisao ?: null, $userId, $now]
            );

            $newId = (int) $this->db->lastInsertId();

            $this->db->executeStatement(
                'INSERT INTO radar_waze_link_log
                 (radar_waze_link_id, changed_by, campo_alterado, valor_anterior, valor_novo, changed_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$newId, $userId, 'waze_link', null, $wazeLink, $now]
            );

            $this->addFlash('success', 'Link Waze cadastrado com sucesso.');
        }

        return $this->redirectToRoute('radar_show', ['id' => $id]);
    }

    private function buildFrom(string $serie): string
    {
        return $serie !== '' ? 'LEFT JOIN radar_faixa rf ON rf.radar_medidor_id = rm.id' : '';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function buildWhere(
        string $uf,
        string $municipio,
        string $resultado,
        string $tipo,
        string $validade,
        string $serie,
        ?array $allowedUfs,
    ): array {
        $parts  = [];
        $params = [];

        if ($allowedUfs !== null) {
            if (count($allowedUfs) === 0) {
                $parts[] = '1=0';
            } else {
                $ph      = implode(',', array_fill(0, count($allowedUfs), '?'));
                $parts[] = "rm.sigla_uf IN ($ph)";
                foreach ($allowedUfs as $ufsVal) {
                    $params[] = $ufsVal;
                }
            }
        }

        if ($uf !== '') {
            $parts[]  = 'rm.sigla_uf = ?';
            $params[] = $uf;
        }
        if ($municipio !== '') {
            $parts[]  = 'rm.municipio LIKE ?';
            $params[] = '%' . $this->escapeLike($municipio) . '%';
        }
        if ($resultado !== '') {
            $parts[]  = 'rm.ultimo_resultado = ?';
            $params[] = $resultado;
        }
        if ($tipo !== '') {
            $parts[]  = 'rm.tipo_medidor = ?';
            $params[] = $tipo;
        }
        if ($serie !== '') {
            $escaped  = $this->escapeLike($serie);
            $parts[]  = '(rf.numero_serie LIKE ? OR rf.numero_inmetro LIKE ?)';
            $params[] = "%$escaped%";
            $params[] = "%$escaped%";
        }

        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $dv   = $this->dateConv('rm.data_validade');

        if ($validade === 'vencido') {
            $parts[]  = "$dv < ?";
            $params[] = $hoje;
        } elseif ($validade === 'valido') {
            $parts[]  = "$dv >= ?";
            $params[] = $hoje;
        } elseif ($validade === '30dias') {
            $parts[]  = "$dv >= ? AND $dv <= ?";
            $params[] = $hoje;
            $params[] = $em30;
        } elseif ($validade === 'recentes30') {
            $ha30dias = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');
            $dve      = $this->dateConv('rm.data_verificacao_efetiva');
            $parts[]  = "rm.data_verificacao_efetiva IS NOT NULL AND $dve >= ? AND $dve <= ?";
            $params[] = $ha30dias;
            $params[] = $hoje;
        }

        return [implode(' AND ', $parts), $params];
    }
}
