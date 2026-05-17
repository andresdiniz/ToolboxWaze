<?php

namespace App\Controller\Admin;

use App\Entity\Solicitacao;
use App\Entity\SolicitacaoTipoResponsavel;
use App\Form\SolicitacaoTipoResponsavelType;
use App\Repository\SolicitacaoTipoResponsavelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/solicitacoes/responsaveis', name: 'admin_sol_responsaveis_')]
#[IsGranted('ROLE_ADMIN')]
class SolicitacaoResponsaveisController extends AbstractController
{
    public function __construct(
        private readonly SolicitacaoTipoResponsavelRepository $repo,
        private readonly EntityManagerInterface $em,
    ) {}

    /** Lista todos os tipos com seus responsaveis atuais. */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $indexed = $this->repo->findAllIndexed();

        // Garante que todos os tipos existam (mesmo sem registro no BD)
        $rows = [];
        foreach (Solicitacao::TIPOS as $valor => $label) {
            $rows[] = [
                'valor'  => $valor,
                'label'  => $label,
                'config' => $indexed[$valor] ?? null,
            ];
        }

        return $this->render('admin/solicitacao_responsaveis/index.html.twig', [
            'rows' => $rows,
        ]);
    }

    /** Edita os responsaveis de um tipo especifico. */
    #[Route('/{tipo}/editar', name: 'editar', methods: ['GET', 'POST'])]
    public function editar(string $tipo, Request $request): Response
    {
        if (!array_key_exists($tipo, Solicitacao::TIPOS)) {
            throw $this->createNotFoundException("Tipo de solicitação desconhecido: $tipo");
        }

        $config = $this->repo->findOrCreateByTipo($tipo);
        $form   = $this->createForm(SolicitacaoTipoResponsavelType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $config->touch();
            $this->em->flush();

            $this->addFlash('success', sprintf(
                'Responsáveis do tipo \u201c%s\u201d atualizados com sucesso.',
                Solicitacao::TIPOS[$tipo]
            ));

            return $this->redirectToRoute('admin_sol_responsaveis_index');
        }

        return $this->render('admin/solicitacao_responsaveis/editar.html.twig', [
            'tipo'   => $tipo,
            'label'  => Solicitacao::TIPOS[$tipo],
            'config' => $config,
            'form'   => $form,
        ]);
    }

    /** Remove todos os responsaveis de um tipo. */
    #[Route('/{tipo}/limpar', name: 'limpar', methods: ['POST'])]
    public function limpar(string $tipo, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('limpar_' . $tipo, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token inválido.');
        }

        $config = $this->repo->findOneBy(['tipo' => $tipo]);
        if ($config) {
            foreach (clone $config->getResponsaveis() as $u) {
                $config->removeResponsavel($u);
            }
            $config->touch();
            $this->em->flush();
        }

        $this->addFlash('success', 'Responsáveis removidos.');
        return $this->redirectToRoute('admin_sol_responsaveis_index');
    }
}
