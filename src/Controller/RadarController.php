<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\AccessControlTrait;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/radares')]
#[IsGranted('ROLE_USER')]
class RadarController extends AbstractController
{
    use AccessControlTrait;

    private const PER_PAGE = 50;

    private const CAMPOS_EDITAVEIS = [
        'sigla_uf'                  => 'UF',
        'uf'                        => 'Estado (nome)',
        'municipio'                 => 'Município',
        'logradouro'                => 'Logradouro',
        'cep'                       => 'CEP',
        'nome_empresa'              => 'Empresa',
        'cnpj_empresa'              => 'CNPJ',
        'tipo_medidor'              => 'Tipo de Medidor',
        'modelo_medidor'            => 'Modelo do Medidor',
        'marca_medidor'             => 'Marca do Medidor',
        'numero_serie'              => 'Nº de Série',
        'numero_certificado'        => 'Nº Certificado',
        'orgao_verificador'         => 'Órgão Verificador',
        'data_ultima_verificacao'   => 'Última Verificação',
        'data_verificacao_efetiva'  => 'Data Verificação Efetiva',
        'data_verificacao'          => 'Data Verificação',
        'data_lacre'                => 'Data Lacre',
        'lacre'                     => 'Lacre',
        'data_validade'             => 'Validade',
        'situacao'                  => 'Situação',
        'capacidade'                => 'Capacidade',
        'latitude'                  => 'Latitude',
        'longitude'                 => 'Longitude',
        'link_waze'                 => 'Link Waze',
    ];

    private const VALIDADE_ISO_EXPR = "STR_TO_DATE(r.data_validade, '%d/%m/%Y')";

    public function __construct(private readonly Connection $db) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Listagem
    // ─────────────────────────────────────────────────────────────────────────
    #[Route('', name: 'radar_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_RADARS);

        $page      = max(1, (int) $request->query->get('page', 1));
        $uf        = trim((string) $request->query->get('uf', ''));
        $municipio = trim((string) $request->query->get('municipio', ''));
        $resultado = trim((string) $request->query->get('resultado', ''));
        $tipo      = trim((string) $request->query->get('tipo', ''));
        $validade  = trim((string) $request->query->get('validade', ''));
        $serie     = trim((string) $request->query->get('serie', ''));

        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $ha30 = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        $viso = self::VALIDADE_ISO_EXPR;

        $where  = ['1=1'];
        $params = [];

        // ── Restrição de UFs do usuário ──────────────────────────────────────
        $ufRestriction = $this->enforceUfsOnQuery('r.sigla_uf');
        if ($ufRestriction['clause'] !== '') {
            $where[]  = $ufRestriction['clause'];
            $params   = array_merge($params, $ufRestriction['params']);
        }

        // Se o usuário pediu uma UF específica, valida que ele tem acesso a ela
        if ($uf !== '') {
            $this->requireUfAccess($uf);
            $where[]  = 'r.sigla_uf = ?';
            $params[] = $uf;
        }
        if ($municipio !== '') {
            $where[]  = 'r.municipio LIKE ?';
            $params[] = "%$municipio%";
        }
        if ($resultado !== '') {
            $where[]  = 'r.situacao = ?';
            $params[] = $resultado;
        }
        if ($tipo !== '') {
            $where[]  = 'r.tipo_medidor = ?';
            $params[] = $tipo;
        }
        if ($serie !== '') {
            $where[]  = 'r.numero_serie LIKE ?';
            $params[] = "%$serie%";
        }
        match ($validade) {
            'valido'     => ($where[] = "$viso >= '$hoje'"),
            '30dias'     => ($where[] = "$viso >= '$hoje' AND $viso <= '$em30'"),
            'vencido'    => ($where[] = "$viso < '$hoje'"),
            'recentes30' => ($where[] = "r.data_verificacao_efetiva >= '$ha30' AND r.data_verificacao_efetiva <= '$hoje'"),
            default      => null,
        };

        $wc = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM radar_medidor r WHERE $wc", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $this->db->fetchAllAssociative(
            "SELECT r.id, r.sigla_uf, r.uf, r.municipio, r.logradouro, r.nome_empresa,
                    r.data_ultima_verificacao, r.data_verificacao_efetiva,
                    r.data_validade, r.situacao, r.tipo_medidor, r.link_waze,
                    DATE_FORMAT($viso, '%Y-%m-%d') AS data_validade_iso
             FROM radar_medidor r WHERE $wc ORDER BY r.sigla_uf, r.municipio LIMIT $offset, " . self::PER_PAGE,
            $params
        );

        // UFs disponíveis no filtro: limitadas às UFs do usuário
        $allowedUfs = $this->allowedUfsForView();
        $ufsQuery   = $allowedUfs !== null
            ? 'SELECT DISTINCT sigla_uf FROM radar_medidor WHERE sigla_uf IS NOT NULL AND sigla_uf IN (?' . str_repeat(',?', count($allowedUfs) - 1) . ') ORDER BY sigla_uf'
            : 'SELECT DISTINCT sigla_uf FROM radar_medidor WHERE sigla_uf IS NOT NULL ORDER BY sigla_uf';
        $ufs = array_column(
            $this->db->fetchAllAssociative($ufsQuery, $allowedUfs ?? []),
            'sigla_uf'
        );

        $resultados = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT situacao FROM radar_medidor WHERE situacao IS NOT NULL ORDER BY situacao'
        ), 'situacao');

        $tipos = array_column($this->db->fetchAllAssociative(
            'SELECT DISTINCT tipo_medidor FROM radar_medidor WHERE tipo_medidor IS NOT NULL ORDER BY tipo_medidor'
        ), 'tipo_medidor');

        // Stats só são exibidos quando sem filtros e sem restrição de UF
        $semFiltros = $uf === '' && $municipio === '' && $resultado === '' && $tipo === '' && $validade === '' && $serie === '';
        $stats = ($semFiltros && $allowedUfs === null)
            ? ($this->db->fetchAssociative(
                "SELECT COUNT(*) AS total,
                        SUM(situacao = 'APROVADO') AS aprovados,
                        SUM(situacao = 'REPROVADO') AS reprovados,
                        SUM(STR_TO_DATE(data_validade, '%d/%m/%Y') < '$hoje') AS vencidos,
                        SUM(STR_TO_DATE(data_validade, '%d/%m/%Y') >= '$hoje'
                            AND STR_TO_DATE(data_validade, '%d/%m/%Y') <= '$em30') AS vencendo,
                        COUNT(DISTINCT sigla_uf) AS estados
                 FROM radar_medidor"
              ) ?: null)
            : null;

        return $this->render('radar/index.html.twig', [
            'rows'        => $rows,
            'page'        => $page,
            'pages'       => $pages,
            'total'       => $total,
            'per_page'    => self::PER_PAGE,
            'stats'       => $stats,
            'ufs'         => $ufs,
            'resultados'  => $resultados,
            'tipos'       => $tipos,
            'hoje'        => $hoje,
            'em30'        => $em30,
            'ha30dias'    => $ha30,
            'filters'     => compact('uf', 'municipio', 'resultado', 'tipo', 'validade', 'serie'),
            'allowedUfs'  => $allowedUfs,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Detalhe
    // ─────────────────────────────────────────────────────────────────────────
    #[Route('/{id}', name: 'radar_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $this->requirePermission(User::PERMISSION_RADARS);

        $viso  = self::VALIDADE_ISO_EXPR;
        $radar = $this->db->fetchAssociative(
            "SELECT r.*, DATE_FORMAT($viso, '%Y-%m-%d') AS data_validade_iso FROM radar_medidor r WHERE r.id = ?",
            [$id]
        );
        if (!$radar) {
            throw $this->createNotFoundException("Radar #{$id} não encontrado.");
        }

        $this->requireUfAccess($radar['sigla_uf'] ?? null);

        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        $em30 = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $ha30 = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        $wazeLink = $this->db->fetchAssociative(
            'SELECT wl.*, u1.email AS inserted_by_email, u2.email AS updated_by_email
             FROM radar_waze_link wl
             LEFT JOIN user u1 ON u1.id = wl.inserted_by
             LEFT JOIN user u2 ON u2.id = wl.updated_by
             WHERE wl.radar_medidor_id = ? ORDER BY wl.id DESC LIMIT 1',
            [$id]
        ) ?: null;

        $wazeLog = $this->db->fetchAllAssociative(
            'SELECT wll.*, u.email AS changed_by_email
             FROM radar_waze_link_log wll
             INNER JOIN radar_waze_link wl ON wl.id = wll.radar_waze_link_id
             LEFT JOIN user u ON u.id = wll.changed_by
             WHERE wl.radar_medidor_id = ?
             ORDER BY wll.changed_at DESC LIMIT 20',
            [$id]
        );

        $historico = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_historico WHERE radar_medidor_id = ? ORDER BY data_laudo DESC LIMIT 10',
            [$id]
        );
        $faixas = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_faixa WHERE radar_medidor_id = ? ORDER BY numero_faixa',
            [$id]
        );

        return $this->render('radar/show.html.twig', [
            'radar'        => $radar,
            'wazeLink'     => $wazeLink,
            'wazeLog'      => $wazeLog,
            'historico'    => $historico,
            'faixas'       => $faixas,
            'hoje'         => $hoje,
            'em30'         => $em30,
            'ha30dias'     => $ha30,
            'wazeErrors'   => [],
            'wazeFormData' => [],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Editar radar
    // ─────────────────────────────────────────────────────────────────────────
    #[Route('/{id}/editar', name: 'radar_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_RADARS);

        $viso  = self::VALIDADE_ISO_EXPR;
        $radar = $this->db->fetchAssociative(
            "SELECT r.*, DATE_FORMAT($viso, '%Y-%m-%d') AS data_validade_iso FROM radar_medidor r WHERE r.id = ?",
            [$id]
        );
        if (!$radar) {
            throw $this->createNotFoundException("Radar #{$id} não encontrado.");
        }

        $this->requireUfAccess($radar['sigla_uf'] ?? null);

        if ($request->isMethod('POST')) {
            /** @var \App\Entity\User $user */
            $user       = $this->getUser();
            $userEmail  = $user->getUserIdentifier();
            $agora      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $alteracoes = [];

            foreach (self::CAMPOS_EDITAVEIS as $campo => $rotulo) {
                if (!$request->request->has($campo)) {
                    continue;
                }
                $novoValor   = trim((string) $request->request->get($campo, '')) ?: null;
                $valorAntigo = isset($radar[$campo]) ? ($radar[$campo] === '' ? null : $radar[$campo]) : null;

                if ($novoValor !== $valorAntigo) {
                    $this->db->insert('radar_edit_log', [
                        'radar_medidor_id' => $id,
                        'campo'            => $campo,
                        'valor_anterior'   => $valorAntigo,
                        'valor_novo'       => $novoValor,
                        'editado_por'      => $userEmail,
                        'editado_em'       => $agora,
                    ]);
                    $alteracoes[$campo] = $novoValor;
                }
            }

            if (!empty($alteracoes)) {
                $alteracoes['updated_at']  = $agora;
                $alteracoes['inserted_by'] = $userEmail;
                $this->db->update('radar_medidor', $alteracoes, ['id' => $id]);
                $count = count($alteracoes) - 2;
                $this->addFlash('success', "$count campo(s) atualizado(s) com sucesso.");
            } else {
                $this->addFlash('info', 'Nenhuma alteração detectada.');
            }

            return $this->redirectToRoute('radar_show', ['id' => $id]);
        }

        $editLog = $this->db->fetchAllAssociative(
            'SELECT * FROM radar_edit_log WHERE radar_medidor_id = ? ORDER BY editado_em DESC LIMIT 30',
            [$id]
        );

        return $this->render('radar/edit.html.twig', [
            'radar'           => $radar,
            'camposEditaveis' => self::CAMPOS_EDITAVEIS,
            'editLog'         => $editLog,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Salvar link Waze
    // ─────────────────────────────────────────────────────────────────────────
    #[Route('/{id}/waze', name: 'radar_waze_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function wazeSave(int $id, Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_RADARS);

        $radar = $this->db->fetchAssociative('SELECT id, sigla_uf, link_waze FROM radar_medidor WHERE id = ?', [$id]);
        if (!$radar) {
            throw $this->createNotFoundException();
        }

        $this->requireUfAccess($radar['sigla_uf'] ?? null);

        $errors   = [];
        $wazeLink = trim((string) $request->request->get('waze_link', ''));
        $motivo   = trim((string) $request->request->get('motivo_revisao', '')) ?: null;

        if ($wazeLink === '') {
            $errors['waze_link'] = 'O link é obrigatório.';
        } elseif (!filter_var($wazeLink, FILTER_VALIDATE_URL)) {
            $errors['waze_link'] = 'URL inválida.';
        } elseif (!str_contains($wazeLink, 'permanentHazards=')) {
            $errors['waze_link'] = 'O link deve conter permanentHazards=NÚMERO.';
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if (!empty($errors)) {
            $viso    = self::VALIDADE_ISO_EXPR;
            $radar   = $this->db->fetchAssociative(
                "SELECT r.*, DATE_FORMAT($viso, '%Y-%m-%d') AS data_validade_iso FROM radar_medidor r WHERE r.id = ?",
                [$id]
            );
            $hoje    = (new \DateTimeImmutable())->format('Y-m-d');
            $em30    = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
            $ha30    = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');
            $wazeRow = $this->db->fetchAssociative(
                'SELECT wl.*, u1.email AS inserted_by_email, u2.email AS updated_by_email
                 FROM radar_waze_link wl
                 LEFT JOIN user u1 ON u1.id = wl.inserted_by
                 LEFT JOIN user u2 ON u2.id = wl.updated_by
                 WHERE wl.radar_medidor_id = ? ORDER BY wl.id DESC LIMIT 1',
                [$id]
            ) ?: null;
            $wazeLog = $this->db->fetchAllAssociative(
                'SELECT wll.*, u.email AS changed_by_email
                 FROM radar_waze_link_log wll
                 INNER JOIN radar_waze_link wl ON wl.id = wll.radar_waze_link_id
                 LEFT JOIN user u ON u.id = wll.changed_by
                 WHERE wl.radar_medidor_id = ?
                 ORDER BY wll.changed_at DESC LIMIT 20',
                [$id]
            );
            return $this->render('radar/show.html.twig', [
                'radar'        => $radar,
                'wazeLink'     => $wazeRow,
                'wazeLog'      => $wazeLog,
                'historico'    => [],
                'faixas'       => [],
                'hoje'         => $hoje,
                'em30'         => $em30,
                'ha30dias'     => $ha30,
                'wazeErrors'   => $errors,
                'wazeFormData' => ['waze_link' => $wazeLink, 'motivo_revisao' => $motivo],
            ]);
        }

        preg_match('/permanentHazards=(\d+)/', $wazeLink, $m);
        $hazardId = (int) ($m[1] ?? 0);
        $agora    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->db->fetchAssociative(
            'SELECT * FROM radar_waze_link WHERE radar_medidor_id = ?', [$id]
        );

        if ($existing) {
            $this->db->insert('radar_waze_link_log', [
                'radar_waze_link_id' => $existing['id'],
                'campo_alterado'     => 'waze_link',
                'valor_anterior'     => $existing['waze_link'],
                'valor_novo'         => $wazeLink,
                'changed_by'         => $user->getId(),
                'changed_at'         => $agora,
            ]);
            $this->db->update('radar_waze_link', [
                'waze_link'           => $wazeLink,
                'permanent_hazard_id' => $hazardId,
                'updated_by'          => $user->getId(),
                'updated_at'          => $agora,
                'observacao'          => $motivo,
            ], ['radar_medidor_id' => $id]);
        } else {
            $this->db->insert('radar_waze_link', [
                'radar_medidor_id'    => $id,
                'waze_link'           => $wazeLink,
                'permanent_hazard_id' => $hazardId,
                'inserted_by'         => $user->getId(),
                'inserted_at'         => $agora,
                'observacao'          => $motivo,
            ]);
        }

        $this->db->update('radar_medidor', ['link_waze' => $wazeLink, 'updated_at' => $agora], ['id' => $id]);

        $this->addFlash('success', 'Link Waze salvo com sucesso.');
        return $this->redirectToRoute('radar_show', ['id' => $id]);
    }
}
