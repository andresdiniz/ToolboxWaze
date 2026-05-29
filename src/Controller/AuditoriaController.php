<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/auditoria', name: 'auditoria_')]
final class AuditoriaController extends AbstractController
{
    private const PER_PAGE = 40;

    public function __construct(
        private readonly Connection $db,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $req): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        /** @var User|null $user */
        $user       = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        $filtroUser  = trim((string) $req->query->get('user', ''));
        $filtroCampo = trim((string) $req->query->get('campo', ''));
        $dataInicio  = trim((string) $req->query->get('inicio', ''));
        $dataFim     = trim((string) $req->query->get('fim', ''));
        $filtroTipo  = trim((string) $req->query->get('tipo', ''));
        $page        = max(1, (int) $req->query->get('page', 1));
        $offset      = ($page - 1) * self::PER_PAGE;

        // ------------------------------------------------------------------
        // Builder de filtros por bloco (cada bloco tem seu campo de UF)
        // ------------------------------------------------------------------
        $blocks = [
            'posto'  => ['ufCol' => 'frr.uf',      'timeCol' => 'wll.changed_at',  'userCol' => 'wll.changed_by',  'campoCol' => 'wll.campo_alterado'],
            'radar'  => ['ufCol' => 'rm.sigla_uf', 'timeCol' => 'wll.changed_at',  'userCol' => 'wll.changed_by',  'campoCol' => 'wll.campo_alterado'],
            'escola' => ['ufCol' => 'e.uf',         'timeCol' => 'wll.alterado_em', 'userCol' => 'wll.alterado_por', 'campoCol' => 'wll.campo'],
        ];

        $blockWhere  = [];
        $blockParams = [];

        foreach ($blocks as $tipo => $cols) {
            $parts  = [];
            $params = [];

            if ($allowedUfs !== null) {
                if (count($allowedUfs) === 0) {
                    $parts[] = '1=0';
                } else {
                    $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
                    $parts[] = "{$cols['ufCol']} IN ($ph)";
                    foreach ($allowedUfs as $uf) { $params[] = $uf; }
                }
            }

            if ($filtroUser !== '') {
                $parts[]  = 'u.email LIKE ?';
                $params[] = "%$filtroUser%";
            }
            if ($filtroCampo !== '') {
                $parts[]  = "{$cols['campoCol']} = ?";
                $params[] = $filtroCampo;
            }
            if ($dataInicio !== '') {
                $parts[]  = "{$cols['timeCol']} >= ?";
                $params[] = $dataInicio . ' 00:00:00';
            }
            if ($dataFim !== '') {
                $parts[]  = "{$cols['timeCol']} <= ?";
                $params[] = $dataFim . ' 23:59:59';
            }

            $blockWhere[$tipo]  = $parts ? 'WHERE ' . implode(' AND ', $parts) : '';
            $blockParams[$tipo] = $params;
        }

        // ------------------------------------------------------------------
        // UNION dos três tipos
        // ------------------------------------------------------------------
        $postoSql = "
            SELECT
                wll.id, wll.campo_alterado AS campo, wll.valor_anterior, wll.valor_novo,
                wll.changed_at AS alterado_em,
                u.email AS changed_by_email,
                frr.id AS objeto_id, frr.razao_social AS objeto_nome,
                frr.municipio, frr.uf,
                'posto' AS tipo
            FROM posto_waze_link_log wll
            JOIN user u ON u.id = wll.changed_by
            JOIN posto_waze_link pwl ON pwl.id = wll.posto_waze_link_id
            JOIN fuel_reseller_raw frr ON frr.id = pwl.posto_id
            {$blockWhere['posto']}
        ";

        $radarSql = "
            SELECT
                wll.id, wll.campo_alterado AS campo, wll.valor_anterior, wll.valor_novo,
                wll.changed_at AS alterado_em,
                u.email AS changed_by_email,
                rm.id AS objeto_id,
                CONCAT_WS(' — ', rm.logradouro, rm.municipio, rm.sigla_uf) AS objeto_nome,
                rm.municipio, rm.sigla_uf AS uf,
                'radar' AS tipo
            FROM radar_waze_link_log wll
            JOIN user u ON u.id = wll.changed_by
            JOIN radar_waze_link rwl ON rwl.id = wll.radar_waze_link_id
            JOIN radar_medidor rm ON rm.id = rwl.radar_medidor_id
            {$blockWhere['radar']}
        ";

        $escolaSql = "
            SELECT
                wll.id, wll.campo AS campo, wll.valor_anterior, wll.valor_novo,
                wll.alterado_em,
                u.email AS changed_by_email,
                e.id AS objeto_id, e.escola AS objeto_nome,
                e.municipio, e.uf,
                'escola' AS tipo
            FROM escola_inep_waze_link_log wll
            JOIN user u ON u.id = wll.alterado_por
            JOIN escola_inep e ON e.id = wll.escola_id
            {$blockWhere['escola']}
        ";

        // Monta os tipos ativos (para filtro de tipo)
        $activeSqls   = [];
        $activeParams = [];

        foreach (['posto' => $postoSql, 'radar' => $radarSql, 'escola' => $escolaSql] as $t => $sql) {
            if ($filtroTipo === '' || $filtroTipo === $t) {
                $activeSqls[]   = $sql;
                $activeParams   = array_merge($activeParams, $blockParams[$t]);
            }
        }

        if (empty($activeSqls)) {
            $activeSqls[]  = "SELECT NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'none' WHERE 1=0";
        }

        $unionSql = implode(' UNION ALL ', $activeSqls);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM ($unionSql) AS combined",
            $activeParams
        );

        $logs = $this->db->fetchAllAssociative(
            "SELECT * FROM ($unionSql) AS combined
             ORDER BY alterado_em DESC
             LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $activeParams
        );

        $usuarios = $this->db->fetchAllAssociative(
            "SELECT DISTINCT u.email
             FROM (
                SELECT changed_by FROM posto_waze_link_log
                UNION
                SELECT changed_by FROM radar_waze_link_log
                UNION
                SELECT alterado_por FROM escola_inep_waze_link_log
             ) all_logs
             JOIN user u ON u.id = all_logs.changed_by
             ORDER BY u.email"
        );

        return $this->render('auditoria/index.html.twig', [
            'logs'       => $logs,
            'total'      => $total,
            'page'       => $page,
            'pages'      => (int) ceil(max(1, $total) / self::PER_PAGE),
            'per_page'   => self::PER_PAGE,
            'filtros'    => compact('filtroUser', 'filtroCampo', 'dataInicio', 'dataFim', 'filtroTipo'),
            'usuarios'   => array_column($usuarios, 'email'),
        ]);
    }
}
