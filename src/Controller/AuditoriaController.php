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

        $parts  = [];
        $params = [];

        if ($allowedUfs !== null && count($allowedUfs) > 0) {
            $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
            $parts[] = "frr.uf IN ($ph)";
            foreach ($allowedUfs as $uf) {
                $params[] = $uf;
            }
        } elseif ($allowedUfs !== null && count($allowedUfs) === 0) {
            $parts[] = '1=0';
        }

        if ($filtroUser !== '') {
            $parts[]  = 'u.email LIKE ?';
            $params[] = "%$filtroUser%";
        }
        if ($filtroCampo !== '') {
            $parts[]  = 'wll.campo_alterado = ?';
            $params[] = $filtroCampo;
        }
        if ($dataInicio !== '') {
            $parts[]  = 'wll.changed_at >= ?';
            $params[] = $dataInicio . ' 00:00:00';
        }
        if ($dataFim !== '') {
            $parts[]  = 'wll.changed_at <= ?';
            $params[] = $dataFim . ' 23:59:59';
        }

        $where = $parts ? 'WHERE ' . implode(' AND ', $parts) : '';

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*)
             FROM posto_waze_link_log wll
             JOIN user u ON u.id = wll.changed_by
             JOIN posto_waze_link pwl ON pwl.id = wll.posto_waze_link_id
             JOIN fuel_reseller_raw frr ON frr.id = pwl.posto_id
             $where",
            $params
        );

        $logs = $this->db->fetchAllAssociative(
            "SELECT
                wll.id, wll.campo_alterado, wll.valor_anterior, wll.valor_novo, wll.changed_at,
                u.email AS changed_by_email,
                frr.id AS posto_id, frr.razao_social, frr.municipio, frr.uf
             FROM posto_waze_link_log wll
             JOIN user u ON u.id = wll.changed_by
             JOIN posto_waze_link pwl ON pwl.id = wll.posto_waze_link_id
             JOIN fuel_reseller_raw frr ON frr.id = pwl.posto_id
             $where
             ORDER BY wll.changed_at DESC
             LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );

        $usuarios = $this->db->fetchAllAssociative(
            "SELECT DISTINCT u.email FROM posto_waze_link_log wll
             JOIN user u ON u.id = wll.changed_by ORDER BY u.email"
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
