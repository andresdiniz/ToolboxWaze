<?php

namespace App\Controller;

use App\Entity\Solicitacao;
use App\Form\SolicitacaoType;
use App\Service\SolicitacaoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/solicitacoes')]
class SolicitacaoController extends AbstractController
{
    public function __construct(
        private readonly SolicitacaoService $solicitacaoService,
    ) {}

    #[Route('/nova', name: 'solicitacao_nova', methods: ['GET', 'POST'])]
    public function nova(Request $request): Response
    {
        $solicitacao = new Solicitacao();
        $tipoAtual   = null;

        // Suporte ao AJAX de pré-visualização de campos dinâmicos
        $ajaxTipo = $request->query->get('_ajax_tipo');
        if ($ajaxTipo) {
            try {
                $solicitacao->setTipo($ajaxTipo);
                $tipoAtual = $ajaxTipo;
            } catch (\Throwable) {}
        }

        $form = $this->createForm(SolicitacaoType::class, $solicitacao);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Resolve tipoAtual para o template de confirmação
            try { $tipoAtual = $solicitacao->getTipo(); } catch (\Throwable) {}

            $dados = [];
            foreach ($form->all() as $fieldName => $field) {
                if (str_starts_with($fieldName, 'dados_')) {
                    $dados[substr($fieldName, 6)] = $field->getData();
                }
            }
            $solicitacao->setDados($dados);

            if ($tipoAtual === Solicitacao::TIPO_OOPS) {
                $arquivos = $request->files->get('arquivos_oops', []);
                $nomes = [];
                foreach ((array) $arquivos as $file) {
                    if ($file && $file->isValid()) {
                        $nome = uniqid('oops_') . '.' . $file->guessExtension();
                        $file->move($this->getParameter('kernel.project_dir') . '/public/uploads/oops', $nome);
                        $nomes[] = $nome;
                    }
                }
                $solicitacao->setArquivos($nomes ?: null);
            }

            $this->solicitacaoService->criar($solicitacao);
            $this->addFlash('success', 'Solicitação enviada com sucesso! Você receberá uma confirmação por e-mail.');
            return $this->redirectToRoute('solicitacao_confirmacao', ['id' => $solicitacao->getId()]);
        }

        // Recupera tipoAtual do form já submetido com erro (reexibição)
        if ($form->isSubmitted() && !$form->isValid()) {
            try { $tipoAtual = $solicitacao->getTipo(); } catch (\Throwable) { $tipoAtual = null; }
        }

        return $this->render('solicitacao/nova.html.twig', [
            'form'      => $form,
            'tipoAtual' => $tipoAtual,
        ]);
    }

    #[Route('/confirmacao/{id}', name: 'solicitacao_confirmacao')]
    public function confirmacao(Solicitacao $solicitacao): Response
    {
        return $this->render('solicitacao/confirmacao.html.twig', ['solicitacao' => $solicitacao]);
    }

    #[Route('/minhas-pendencias', name: 'solicitacao_pendencias')]
    #[IsGranted('ROLE_USER')]
    public function minhasPendencias(): Response
    {
        $user = $this->getUser();
        return $this->render('solicitacao/pendencias.html.twig', [
            'pendencias' => $this->solicitacaoService->getPendenciasDoUsuario($user),
            'total'      => $this->solicitacaoService->countPendencias($user),
        ]);
    }

    #[Route('/{id}', name: 'solicitacao_detalhe')]
    #[IsGranted('ROLE_USER')]
    public function detalhe(Solicitacao $solicitacao): Response
    {
        $this->denyAccessUnlessGranted('SOLICITACAO_VER', $solicitacao);
        return $this->render('solicitacao/detalhe.html.twig', ['solicitacao' => $solicitacao]);
    }

    #[Route('/{id}/resolver', name: 'solicitacao_resolver', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resolver(Solicitacao $solicitacao, Request $request): Response
    {
        $this->denyAccessUnlessGranted('SOLICITACAO_RESOLVER', $solicitacao);
        if (!$this->isCsrfTokenValid('resolver_' . $solicitacao->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token inválido.');
        }
        $this->solicitacaoService->resolver($solicitacao, $this->getUser(), $request->request->get('nota'));
        $this->addFlash('success', 'Solicitação marcada como resolvida.');
        return $this->redirectToRoute('solicitacao_pendencias');
    }
}
