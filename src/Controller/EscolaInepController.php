<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\AccessControlTrait;
use App\Entity\EscolaInep;
use App\Entity\EscolaInepComentario;
use App\Entity\EscolaInepWazeLinkLog;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/escolas')]
#[IsGranted('ROLE_USER')]
class EscolaInepController extends AbstractController
{
    use AccessControlTrait;

    private const PER_PAGE = 50;

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
    ) {
    }

    // -------------------------------------------------------------------------

    #[Route('', name: 'escola_inep_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_ESCOLAS);

        $page        = max(1, (int) $request->query->get('page', 1));
        $busca       = trim((string) $request->query->get('busca', ''));
        $uf          = trim((string) $request->query->get('uf', ''));
        $municipio   = trim((string) $request->query->get('municipio', ''));
        $dependencia = trim((string) $request->query->get('dependencia', ''));
        $localizacao = trim((string) $request->query->get('localizacao', ''));
        $situacao    = trim((string) $request->query->get('situacao', ''));
        $offset      = ($page - 1) * self::PER_PAGE;

        // Salva os filtros ativos na sessão para restaurar ao clicar em Voltar
        $request->getSession()->set('escola_filtros', [
            'busca'       => $busca,
            'uf'          => $uf,
            'municipio'   => $municipio,
            'dependencia' => $dependencia,
            'localizacao' => $localizacao,
            'situacao'    => $situacao,
            'page'        => $page,
        ]);

        $where  = ['1=1'];
        $params = [];

        $ufRestriction = $this->enforceUfsOnQuery('e.uf');
        if ($ufRestriction['clause'] !== '') {
            $where[]  = $ufRestriction['clause'];
            $params   = array_merge($params, $ufRestriction['params']);
        }

        if ($busca !== '') {
            $where[]  = '(e.escola LIKE ? OR e.codigo_inep LIKE ? OR e.municipio LIKE ?)';
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
        if ($dependencia !== '') {
            $where[]  = 'e.dependencia_administrativa = ?';
            $params[] = $dependencia;
        }
        if ($localizacao !== '') {
            $where[]  = 'e.localizacao = ?';
            $params[] = $localizacao;
        }

        match ($situacao) {
            'ativa'      => ($where[] = "(e.restricao_atendimento IS NULL OR UPPER(e.restricao_atendimento) LIKE '%SEM RESTRI%')"),
            'paralisada' => ($where[] = "UPPER(e.restricao_atendimento) LIKE '%PARALISADA%'"),
            'sem_link'   => ($where[] = '(e.link_waze IS NULL OR e.link_waze = \'\')'),
            'com_link'   => ($where[] = 'e.link_waze IS NOT NULL AND e.link_waze != \'\'' ),
            default      => null,
        };

        $whereClause = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM escola_inep e WHERE $whereClause",
            $params
        );
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $this->db->fetchAllAssociative(
            "SELECT e.id, e.escola, e.codigo_inep, e.uf, e.municipio,
                    e.localizacao, e.dependencia_administrativa,
                    e.categoria_administrativa, e.porte, e.etapas_ensino,
                    e.restricao_atendimento, e.latitude, e.longitude,
                    e.link_waze, e.permanent_hazard_id, e.link_area_escolar
             FROM escola_inep e
             WHERE $whereClause
             ORDER BY e.escola
             LIMIT $offset, " . self::PER_PAGE,
            $params
        );

        $allowedUfs = $this->allowedUfsForView();

        $stats = null;
        if ($busca === '' && $uf === '' && $municipio === '' && $dependencia === '' && $localizacao === '' && $situacao === '' && $allowedUfs === null) {
            $stats = $this->db->fetchAssociative(
                "SELECT COUNT(*)                                    AS total,
                        COUNT(DISTINCT e.uf)                        AS estados,
                        COUNT(DISTINCT CONCAT(e.uf, e.municipio))   AS municipios
                 FROM escola_inep e"
            ) ?: null;
        }

        $ufsQuery = $allowedUfs !== null
            ? 'SELECT DISTINCT uf FROM escola_inep WHERE uf IS NOT NULL AND uf IN (?' . str_repeat(',?', count($allowedUfs) - 1) . ') ORDER BY uf'
            : 'SELECT DISTINCT uf FROM escola_inep WHERE uf IS NOT NULL ORDER BY uf';
        $ufs = array_column(
            $this->db->fetchAllAssociative($ufsQuery, $allowedUfs ?? []),
            'uf'
        );

        $dependencias = array_column(
            $this->db->fetchAllAssociative(
                'SELECT DISTINCT dependencia_administrativa FROM escola_inep
                 WHERE dependencia_administrativa IS NOT NULL ORDER BY dependencia_administrativa'
            ),
            'dependencia_administrativa'
        );
        $localizacoes = array_column(
            $this->db->fetchAllAssociative(
                'SELECT DISTINCT localizacao FROM escola_inep
                 WHERE localizacao IS NOT NULL ORDER BY localizacao'
            ),
            'localizacao'
        );

        return $this->render('escola_inep/index.html.twig', [
            'rows'         => $rows,
            'page'         => $page,
            'pages'        => $pages,
            'total'        => $total,
            'perPage'      => self::PER_PAGE,
            'stats'        => $stats,
            'ufs'          => $ufs,
            'dependencias' => $dependencias,
            'localizacoes' => $localizacoes,
            'allowedUfs'   => $allowedUfs,
            'filters'      => [
                'busca'       => $busca,
                'uf'          => $uf,
                'municipio'   => $municipio,
                'dependencia' => $dependencia,
                'localizacao' => $localizacao,
                'situacao'    => $situacao,
            ],
        ]);
    }

    // -------------------------------------------------------------------------

    #[Route('/{id}', name: 'escola_inep_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(int $id, Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_ESCOLAS);

        /** @var EscolaInep|null $escola */
        $escola = $this->em->find(EscolaInep::class, $id);

        if (!$escola) {
            throw $this->createNotFoundException("Escola #{$id} não encontrada.");
        }

        $this->requireUfAccess($escola->getUf());

        $linkLogs = $this->em->getRepository(EscolaInepWazeLinkLog::class)
            ->findBy(['escola' => $escola], ['alteradoEm' => 'DESC'], 20);

        $voltarParams = $request->getSession()->get('escola_filtros', []);

        return $this->render('escola_inep/show.html.twig', [
            'escola'       => $escola,
            'linkLogs'     => $linkLogs,
            'voltarParams' => $voltarParams,
        ]);
    }

    // -------------------------------------------------------------------------

    #[Route('/{id}/link', name: 'escola_inep_link_save', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function linkSave(int $id, Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_ESCOLAS);

        /** @var EscolaInep|null $escola */
        $escola = $this->em->find(EscolaInep::class, $id);
        if (!$escola) {
            throw $this->createNotFoundException();
        }

        $this->requireUfAccess($escola->getUf());

        $novoLinkWaze        = trim((string) $request->request->get('link_waze', '')) ?: null;
        $novoLinkAreaEscolar = trim((string) $request->request->get('link_area_escolar', '')) ?: null;
        $observacao          = trim((string) $request->request->get('observacao', '')) ?: null;
        $user                = $this->getUser();

        if ($novoLinkWaze !== $escola->getLinkWaze()) {
            $log = (new EscolaInepWazeLinkLog())
                ->setEscola($escola)
                ->setCampo('link_waze')
                ->setValorAnterior($escola->getLinkWaze())
                ->setValorNovo($novoLinkWaze)
                ->setAlteradoPor($user)
                ->setObservacao($observacao);
            $this->em->persist($log);
            $escola->setLinkWaze($novoLinkWaze);
        }

        if ($novoLinkAreaEscolar !== $escola->getLinkAreaEscolar()) {
            $log = (new EscolaInepWazeLinkLog())
                ->setEscola($escola)
                ->setCampo('link_area_escolar')
                ->setValorAnterior($escola->getLinkAreaEscolar())
                ->setValorNovo($novoLinkAreaEscolar)
                ->setAlteradoPor($user)
                ->setObservacao($observacao);
            $this->em->persist($log);
            $escola->setLinkAreaEscolar($novoLinkAreaEscolar);
        }

        $this->em->flush();

        $this->addFlash('success', 'Links atualizados com sucesso.');

        return $this->redirectToRoute('escola_inep_show', ['id' => $id]);
    }

    // -------------------------------------------------------------------------

    #[Route('/{id}/comentario', name: 'escola_inep_comentario_add', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function comentarioAdd(int $id, Request $request): Response
    {
        $this->requirePermission(User::PERMISSION_ESCOLAS);

        /** @var EscolaInep|null $escola */
        $escola = $this->em->find(EscolaInep::class, $id);
        if (!$escola) {
            throw $this->createNotFoundException();
        }

        $this->requireUfAccess($escola->getUf());

        $texto = trim((string) $request->request->get('texto', ''));
        if ($texto === '') {
            $this->addFlash('warning', 'O comentário não pode estar vazio.');
            return $this->redirectToRoute('escola_inep_show', ['id' => $id]);
        }

        $comentario = (new EscolaInepComentario())
            ->setEscola($escola)
            ->setAutor($this->getUser())
            ->setTexto($texto);

        $this->em->persist($comentario);
        $this->em->flush();

        $this->addFlash('success', 'Comentário adicionado.');

        return $this->redirectToRoute('escola_inep_show', ['id' => $id]);
    }
}
