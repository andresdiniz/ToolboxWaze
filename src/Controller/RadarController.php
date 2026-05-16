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

    public function __construct(
        private readonly Connection $db,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $req): Response
    {
        /** @var User|null $user */
        $user        = $this->getUser();
        $allowedUfs  = $user?->getUfsForQuery(); // null = sem restrição

        $uf        = strtoupper(trim((string) $req->query->get('uf', '')));
        $municipio = trim((string) $req->query->get('municipio', ''));
        $resultado = trim((string) $req->query->get('resultado', ''));
        $tipo      = trim((string) $req->query->get('tipo', ''));
        $validade  = trim((string) $req->query->get('validade', ''));
        $serie     = trim((string) $req->query->get('serie', ''));
        $page      = max(1, (int) $req->query->get('page', 1));
        $offset    = ($page - 1) * self::PER_PAGE;

        // Se o usuário tentou filtrar um UF que não tem acesso, ignora
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

        $rows = $this->db->fetchAllAssociative(
            "SELECT DISTINCT rm.id, rm.sigla_uf, rm.estado, rm.municipio,
                    rm.local_verificacao, rm.data_ultima_verificacao,
                    rm.data_validade, rm.ultimo_resultado,
                    rm.tipo_medidor, rm.proprietario_nome
             FROM radar_medidor rm $baseFrom
             $whereClause
             ORDER BY rm.sigla_uf, rm.municipio, rm.local_verificacao
             LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );

        // UFs disponíveis no filtro = interseção com o que o usuário pode ver
        $ufsQuery = 'SELECT DISTINCT sigla_uf FROM radar_medidor WHERE sigla_uf IS NOT NULL';
        $ufsParams = [];
        if ($allowedUfs !== null && count($allowedUfs) > 0) {
            $placeholders = implode(',', array_fill(0, count($allowedUfs), '?'));
            $ufsQuery .= " AND sigla_uf IN ($placeholders)";
            $ufsParams = $allowedUfs;
        } elseif ($allowedUfs !== null && count($allowedUfs) === 0) {
            $ufsQuery .= ' AND 1=0'; // sem acesso a nenhum estado
        }
        $ufsQuery .= ' ORDER BY sigla_uf';
        $ufs = array_column($this->db->fetchAllAssociative($ufsQuery, $ufsParams), 'sigla_uf');

        $resultados = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT ultimo_resultado FROM radar_medidor WHERE ultimo_resultado IS NOT NULL ORDER BY ultimo_resultado'
        ), 'ultimo_resultado');

        $tipos = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT tipo_medidor FROM radar_medidor WHERE tipo_medidor IS NOT NULL ORDER BY tipo_medidor'
        ), 'tipo_medidor');

        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');

        // Stats filtradas pelo acesso do usuário
        $statsWhere  = '';
        $statsParams = ['hoje' => $hoje, 'em30' => $em30];
        if ($allowedUfs !== null && count($allowedUfs) > 0) {
            $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
            $statsWhere = " WHERE sigla_uf IN ($ph)";
            $statsParams = array_merge($allowedUfs, [$hoje, $em30]);
            $stats = $this->db->fetchAssociative(
                "SELECT COUNT(*) AS total,
                        SUM(ultimo_resultado = 'APROVADO') AS aprovados,
                        SUM(ultimo_resultado = 'REPROVADO') AS reprovados,
                        SUM(data_validade < ?) AS vencidos,
                        SUM(data_validade BETWEEN ? AND ?) AS vencendo,
                        COUNT(DISTINCT sigla_uf) AS estados
                 FROM radar_medidor $statsWhere",
                array_merge($allowedUfs, [$hoje, $hoje, $em30])
            );
        } elseif ($allowedUfs !== null && count($allowedUfs) === 0) {
            $stats = ['total' => 0, 'aprovados' => 0, 'reprovados' => 0, 'vencidos' => 0, 'vencendo' => 0, 'estados' => 0];
        } else {
            $stats = $this->db->fetchAssociative(
                "SELECT COUNT(*) AS total,
                        SUM(ultimo_resultado = 'APROVADO') AS aprovados,
                        SUM(ultimo_resultado = 'REPROVADO') AS reprovados,
                        SUM(data_validade < :hoje) AS vencidos,
                        SUM(data_validade BETWEEN :hoje AND :em30) AS vencendo,
                        COUNT(DISTINCT sigla_uf) AS estados
                 FROM radar_medidor",
                ['hoje' => $hoje, 'em30' => $em30]
            );
        }

        return $this->render('radar/index.html.twig', [
            'rows'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => self::PER_PAGE,
            'pages'       => (int) ceil($total / self::PER_PAGE),
            'filters'     => compact('uf', 'municipio', 'resultado', 'tipo', 'validade', 'serie'),
            'ufs'         => $ufs,
            'resultados'  => $resultados,
            'tipos'       => $tipos,
            'stats'       => $stats,
            'hoje'        => $hoje,
            'em30'        => $em30,
            'allowedUfs'  => $allowedUfs, // para o template mostrar badge de restrição
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'])]
    public function show(int $id): Response
    {
        /** @var User|null $user */
        $user  = $this->getUser();
        $radar = $this->db->fetchAssociative('SELECT * FROM radar_medidor WHERE id = ?', [$id]);

        if (!$radar) {
            throw $this->createNotFoundException('Radar não encontrado.');
        }

        // Bloqueia acesso direto via URL a estados não permitidos
        if ($user && !$user->canAccessUf((string) ($radar['sigla_uf'] ?? ''))) {
            throw $this->createAccessDeniedException('Você não tem acesso a dados deste estado.');
        }

        $faixas    = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_faixa WHERE radar_medidor_id = ? ORDER BY numero_faixa', [$id]
        );
        $historico = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_historico WHERE radar_medidor_id = ? ORDER BY ano DESC, data_laudo DESC', [$id]
        );

        return $this->render('radar/show.html.twig', [
            'radar'     => $radar,
            'faixas'    => $faixas,
            'historico' => $historico,
        ]);
    }

    // -------------------------------------------------------------------------

    private function buildFrom(string $serie): string
    {
        return $serie !== '' ? 'LEFT JOIN radar_faixa rf ON rf.radar_medidor_id = rm.id' : '';
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

        // Restrição automática de estados do usuário
        if ($allowedUfs !== null) {
            if (count($allowedUfs) === 0) {
                $parts[] = '1=0'; // sem acesso
            } else {
                $ph = implode(',', array_fill(0, count($allowedUfs), '?'));
                $parts[] = "rm.sigla_uf IN ($ph)";
                foreach ($allowedUfs as $ufsVal) {
                    $params[] = $ufsVal;
                }
            }
        }

        if ($uf !== '') {
            $parts[]      = 'rm.sigla_uf = ?';
            $params[]     = $uf;
        }
        if ($municipio !== '') {
            $parts[]  = 'rm.municipio LIKE ?';
            $params[] = "%$municipio%";
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
            $parts[]  = '(rf.numero_serie LIKE ? OR rf.numero_inmetro LIKE ?)';
            $params[] = "%$serie%";
            $params[] = "%$serie%";
        }

        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        if ($validade === 'vencido') {
            $parts[]  = 'rm.data_validade < ?';
            $params[] = $hoje;
        } elseif ($validade === 'valido') {
            $parts[]  = 'rm.data_validade >= ?';
            $params[] = $hoje;
        } elseif ($validade === '30dias') {
            $parts[]  = 'rm.data_validade BETWEEN ? AND ?';
            $params[] = $hoje;
            $params[] = $em30;
        }

        return [implode(' AND ', $parts), $params];
    }
}
