<?php

namespace App\Controller;

use App\Entity\Solicitacao;
use App\Form\SolicitacaoType;
use App\Repository\SolicitacaoRepository;
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
        private readonly SolicitacaoService    $solicitacaoService,
        private readonly SolicitacaoRepository $solicitacaoRepo,
    ) {}

    /**
     * Rota AJAX dedicada — retorna APENAS o fragmento de campos dinâmicos.
     *
     * Parâmetros GET:
     *   tipo       – tipo da solicitação (ex: "nivel")
     *   tipoNivel  – "upgrade" | "downgrade" | (ausente = nenhum escolhido ainda)
     *   souChamp   – "1" se o checkbox de confirmação Champ estiver marcado
     *
     * ATENÇÃO: se o usuário autenticado já tem ROLE_CHAMP e tipoNivel=downgrade,
     * souChamp é inferido automaticamente como true — dispensando o checkbox.
     */
    #[Route('/campos', name: 'solicitacao_campos_ajax', methods: ['GET'])]
    public function camposAjax(Request $request): Response
    {
        $isChamp   = $this->isGranted('ROLE_CHAMP');
        $tipo      = $request->query->get('tipo', '');
        $tipoNivel = $request->query->get('tipoNivel');
        $souChamp  = (bool) $request->query->get('souChamp');

        if (!in_array($tipoNivel, ['upgrade', 'downgrade', null], true)) {
            $tipoNivel = null;
        }

        // Champ fazendo downgrade: infere souChamp automaticamente
        // O checkbox de confirmação fica oculto/marcado implicitamente
        if ($isChamp && $tipoNivel === 'downgrade') {
            $souChamp = true;
        }

        $solicitacao = new Solicitacao();
        try { $solicitacao->setTipo($tipo); } catch (\Throwable) { $tipo = ''; }

        $form = $this->createForm(SolicitacaoType::class, $solicitacao, [
            'is_champ'        => $isChamp,
            'ajax_tipo_nivel' => $tipoNivel,
            'ajax_sou_champ'  => $souChamp,
        ]);

        return new Response(
            $this->renderView('solicitacao/_campos.html.twig', [
                'form'           => $form->createView(),
                'tipoAtual'      => $tipo,
                'isChamp'        => $isChamp,
                'tipoNivelAtual' => $tipoNivel,
                'souChampAuto'   => $isChamp && $tipoNivel === 'downgrade',
            ])
        );
    }

    #[Route('', name: 'solicitacao_hub', methods: ['GET', 'POST'])]
    public function hub(Request $request): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isChamp = $this->isGranted('ROLE_CHAMP');
        $user     = $this->getUser();
        $abaAtual = $request->query->get('aba', 'nova');

        if ($abaAtual === 'historico' && !$user) {
            $this->addFlash('warning', 'Faça login para ver seu histórico.');
            return $this->redirectToRoute('app_login');
        }

        $solicitacao = new Solicitacao();
        $tipoAtual   = null;

        $postData      = $request->request->all('solicitacao') ?? [];
        $tipoDoPost    = $postData['tipo']             ?? null;
        $tipoNivelPost = $postData['dados_tipoNivel']  ?? null;
        $souChampPost  = !empty($postData['dados_souChamp']);

        if ($tipoDoPost) {
            try { $solicitacao->setTipo($tipoDoPost); $tipoAtual = $tipoDoPost; } catch (\Throwable) {}
        }

        if (!in_array($tipoNivelPost, ['upgrade', 'downgrade', null], true)) {
            $tipoNivelPost = null;
        }

        // Mesma inferência automática no submit real
        if ($isChamp && $tipoNivelPost === 'downgrade') {
            $souChampPost = true;
        }

        $form = $this->createForm(SolicitacaoType::class, $solicitacao, [
            'is_champ'        => $isChamp,
            'ajax_tipo_nivel' => $tipoNivelPost,
            'ajax_sou_champ'  => $souChampPost,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $tipoNivelSubmetido = $form->has('dados_tipoNivel')
                ? $form->get('dados_tipoNivel')->getData()
                : null;

            if ($tipoNivelSubmetido === 'downgrade' && !$isChamp) {
                $this->addFlash('danger', 'Apenas Champs podem solicitar downgrade de nível.');
                return $this->redirectToRoute('solicitacao_hub');
            }

            $dados = [];
            foreach ($form->all() as $fieldName => $field) {
                if (str_starts_with($fieldName, 'dados_')) {
                    $dados[substr($fieldName, 6)] = $field->getData();
                }
            }
            $solicitacao->setDados($dados);

            if ($tipoAtual === Solicitacao::TIPO_OOPS) {
                $arquivos = $request->files->get('arquivos_oops', []);
                $nomes    = [];
                $dir      = $this->getParameter('kernel.project_dir') . '/public/uploads/oops';
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                foreach ((array) $arquivos as $file) {
                    if ($file && $file->isValid()) {
                        $nome = uniqid('oops_') . '.' . $file->guessExtension();
                        $file->move($dir, $nome);
                        $nomes[] = $nome;
                    }
                }
                $solicitacao->setArquivos($nomes ?: null);
            }

            $this->solicitacaoService->criar($solicitacao);
            $this->addFlash('success', 'Solicitação enviada com sucesso!');
            return $this->redirectToRoute('solicitacao_confirmacao', ['id' => $solicitacao->getId()]);
        }

        if ($form->isSubmitted() && !$form->isValid()) {
            $abaAtual = 'nova';
        }

        $historico = [];
        if ($user && $abaAtual === 'historico') {
            $historico = $this->solicitacaoRepo->findByEmail($user->getEmail());
        }

        $gestaoLista  = [];
        $contadores   = [];
        $filtroStatus = null;
        $filtroTipo   = null;

        if ($abaAtual === 'gestao') {
            if ($isAdmin) {
                $filtroStatus = $request->query->get('status') ?: null;
                $filtroTipo   = $request->query->get('tipo')   ?: null;
                $gestaoLista  = $this->solicitacaoRepo->findParaGestao(
                    $filtroStatus, $filtroTipo, excluirDowngrade: true
                );
                $contadores = $this->solicitacaoRepo->countByStatus(excluirDowngrade: true);
            } elseif ($isChamp) {
                $filtroStatus = $request->query->get('status') ?: null;
                $gestaoLista  = $this->solicitacaoRepo->findParaGestao(
                    $filtroStatus,
                    tipo: Solicitacao::TIPO_NIVEL,
                    apenasDowngrade: true
                );
                $contadores = $this->solicitacaoRepo->countByStatus(
                    tipo: Solicitacao::TIPO_NIVEL,
                    apenasDowngrade: true
                );
            }
        }

        return $this->render('solicitacao/hub.html.twig', [
            'form'         => $form,
            'tipoAtual'    => $tipoAtual,
            'abaAtual'     => $abaAtual,
            'isAdmin'      => $isAdmin,
            'isChamp'      => $isChamp,
            'historico'    => $historico,
            'gestaoLista'  => $gestaoLista,
            'contadores'   => $contadores,
            'filtroStatus' => $filtroStatus,
            'filtroTipo'   => $filtroTipo,
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
        return $this->redirectToRoute('solicitacao_hub', ['aba' => 'historico']);
    }

    #[Route('/{id}', name: 'solicitacao_detalhe')]
    #[IsGranted('ROLE_USER')]
    public function detalhe(Solicitacao $solicitacao): Response
    {
        $this->denyAccessUnlessGranted('SOLICITACAO_VER', $solicitacao);
        return $this->render('solicitacao/detalhe.html.twig', [
            'solicitacao' => $solicitacao,
            'comentarios' => $solicitacao->getComentarios(),
        ]);
    }

    #[Route('/{id}/resolver', name: 'solicitacao_resolver', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resolver(Solicitacao $solicitacao, Request $request): Response
    {
        $this->denyAccessUnlessGranted('SOLICITACAO_RESOLVER', $solicitacao);

        if (!$this->isCsrfTokenValid('resolver_' . $solicitacao->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $novoStatus = $request->request->get('desfecho') ?? $request->request->get('status');

        if (!$novoStatus || !array_key_exists($novoStatus, Solicitacao::STATUS_LABELS)) {
            $this->addFlash('danger', 'Status inválido.');
            return $this->redirectToRoute('solicitacao_detalhe', ['id' => $solicitacao->getId()]);
        }

        $isDowngrade = $solicitacao->getTipo() === Solicitacao::TIPO_NIVEL
            && ($solicitacao->getDados()['tipoNivel'] ?? null) === 'downgrade';

        if ($isDowngrade && !$this->isGranted('ROLE_CHAMP')) {
            $this->addFlash('danger', 'Apenas Champs podem processar downgrade.');
            return $this->redirectToRoute('solicitacao_hub', ['aba' => 'gestao']);
        }

        $nota = trim((string) $request->request->get('nota', '')) ?: null;
        $this->solicitacaoService->mudarStatus($solicitacao, $novoStatus, $this->getUser(), $nota);

        $label = Solicitacao::STATUS_LABELS[$novoStatus] ?? $novoStatus;
        $this->addFlash('success', "Solicitação atualizada para: $label.");

        return $this->redirectToRoute('solicitacao_hub', ['aba' => 'gestao']);
    }
}
