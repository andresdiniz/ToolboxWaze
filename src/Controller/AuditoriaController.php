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
        $page        = max(1, (int) $req->query->get('page', 1));
        $offset      = ($page - 1) * self::PER_PAGE;

        // -----------------------------------------------------------------
        // Monta os filtros compartilhados entre as duas branches do UNION
        // -----------------------------------------------------------------
        $postoParts  = [];
        $postoParams = [];
        $radarParts  = [];
        $radarParams = [];

        // Filtro de UFs restritas (aplicado separadamente porque o campo difere)
        if ($allowedUfs !== null && count($allowedUfs) > 0) {
            $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
            $postoParts[]  = "frr.uf IN ($ph)";
            $radarParts[]  = "rm.sigla_uf IN ($ph)";
            foreach ($allowedUfs as $uf) {
                $postoParams[] = $uf;
                $radarParams[] = $uf;
            }
        } elseif ($allowedUfs !== null && count($allowedUfs) === 0) {
            // Sem acesso a nenhum estado: retorna nada
            $postoParts[] = '1=0';
            $radarParts[] = '1=0';
        }

        if ($filtroUser !== '') {
            $postoParts[]  = 'u.email LIKE ?';
            $postoParams[] = "%$filtroUser%";
            $radarParts[]  = 'u.email LIKE ?';
            $radarParams[] = "%$filtroUser%";
        }
        if ($filtroCampo !== '') {
            $postoParts[]  = 'wll.campo_alterado = ?';
            $postoParams[] = $filtroCampo;
            $radarParts[]  = 'wll.campo_alterado = ?';
            $radarParams[] = $filtroCampo;
        }
        if ($dataInicio !== '') {
            $postoParts[]  = 'wll.changed_at >= ?';
            $postoParams[] = $dataInicio . ' 00:00:00';
            $radarParts[]  = 'wll.changed_at >= ?';
            $radarParams[] = $dataInicio . ' 00:00:00';
        }
        if ($dataFim !== '') {
            $postoParts[]  = 'wll.changed_at <= ?';
            $postoParams[] = $dataFim . ' 23:59:59';
            $radarParts[]  = 'wll.changed_at <= ?';
            $radarParams[] = $dataFim . ' 23:59:59';
        }

        $postoWhere = $postoParts ? 'WHERE ' . implode(' AND ', $postoParts) : '';
        $radarWhere = $radarParts ? 'WHERE ' . implode(' AND ', $radarParts) : '';

        // Parâmetros unidos na ordem certa para COUNT e para SELECT
        $allParams = array_merge($postoParams, $radarParams);

        // -----------------------------------------------------------------
        // CTE / UNION para contar e paginar os dois tipos juntos
        // -----------------------------------------------------------------
        $unionSql = "
            SELECT
                wll.id, wll.campo_alterado, wll.valor_anterior, wll.valor_novo, wll.changed_at,
                u.email AS changed_by_email,
                frr.id AS objeto_id, frr.razao_social AS objeto_nome, frr.municipio, frr.uf,
                'posto' AS tipo
            FROM posto_waze_link_log wll
            JOIN user u ON u.id = wll.changed_by
            JOIN posto_waze_link pwl ON pwl.id = wll.posto_waze_link_id
            JOIN fuel_reseller_raw frr ON frr.id = pwl.posto_id
            $postoWhere

            UNION ALL

            SELECT
                wll.id, wll.campo_alterado, wll.valor_anterior, wll.valor_novo, wll.changed_at,
                u.email AS changed_by_email,
                rm.id AS objeto_id,
                CONCAT_WS(' — ', rm.logradouro, rm.municipio, rm.sigla_uf) AS objeto_nome,
                rm.municipio, rm.sigla_uf AS uf,
                'radar' AS tipo
            FROM radar_waze_link_log wll
            JOIN user u ON u.id = wll.changed_by
            JOIN radar_waze_link rwl ON rwl.id = wll.radar_waze_link_id
            JOIN radar_medidor rm ON rm.id = rwl.radar_medidor_id
            $radarWhere
        ";

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM ($unionSql) AS combined",
            $allParams
        );

        $logs = $this->db->fetchAllAssociative(
            "SELECT * FROM ($unionSql) AS combined
             ORDER BY changed_at DESC
             LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $allParams
        );

        $usuarios = $this->db->fetchAllAssociative(
            "SELECT DISTINCT u.email
             FROM (
                SELECT changed_by FROM posto_waze_link_log
                UNION
                SELECT changed_by FROM radar_waze_link_log
             ) all_logs
             JOIN user u ON u.id = all_logs.changed_by
             ORDER BY u.email"
        );

        return $this->render('auditoria/index.html.twig', [
            'logs'     => $logs,
            'total'    => $total,
            'page'     => $page,
            'pages'    => (int) ceil(max(1, $total) / self::PER_PAGE),
            'per_page' => self::PER_PAGE,
            'filtros'  => compact('filtroUser', 'filtroCampo', 'dataInicio', 'dataFim'),
            'usuarios' => array_column($usuarios, 'email'),
        ]);
    }
}
