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

    #[Route('', name: 'solicitacao_hub', methods: ['GET', 'POST'])]
    public function hub(Request $request): Response
    {
        $isAdmin  = $this->isGranted('ROLE_ADMIN');
        $user     = $this->getUser();
        $abaAtual = $request->query->get('aba', 'nova');

        // Aba 'historico' exige login
        if ($abaAtual === 'historico' && !$user) {
            $this->addFlash('warning', 'Faça login para ver seu histórico de solicitações.');
            return $this->redirectToRoute('app_login');
        }

        $solicitacao = new Solicitacao();
        $tipoAtual   = null;

        // IMPORTANTE: setar o tipo ANTES de createForm para que o PRE_SET_DATA
        // do SolicitacaoType já receba a entidade com tipo definido e adicione
        // os campos dinâmicos corretos (usado pelo AJAX de campos dinâmicos).
        $ajaxTipo = $request->query->get('_ajax_tipo');
        if ($ajaxTipo) {
            try { $solicitacao->setTipo($ajaxTipo); $tipoAtual = $ajaxTipo; } catch (\Throwable) {}
        }

        $form = $this->createForm(SolicitacaoType::class, $solicitacao);
        $form->handleRequest($request);

        // ── Resposta AJAX: retorna apenas o fragmento #campos-dinamicos ────────
        // O JS faz fetch com header X-Requested-With: XMLHttpRequest e parâmetro
        // _ajax_tipo. Renderizamos o hub normalmente e extraímos só o fragmento,
        // evitando duplicar templates ou criar rotas extras.
        if ($ajaxTipo && $request->isXmlHttpRequest()) {
            $html = $this->renderView('solicitacao/hub.html.twig', [
                'form'         => $form,
                'tipoAtual'    => $tipoAtual,
                'abaAtual'     => 'nova',
                'isAdmin'      => $isAdmin,
                'historico'    => [],
                'gestaoLista'  => [],
                'contadores'   => [],
                'filtroStatus' => null,
                'filtroTipo'   => null,
            ]);
            // Extrai o conteúdo do div#campos-dinamicos via regex simples
            if (preg_match('/<div id="campos-dinamicos">(.*?)<\/div>\s*<div class="d-flex justify/s', $html, $m)) {
                return new Response('<div id="campos-dinamicos">' . $m[1] . '</div>');
            }
            // Fallback: retorna o HTML completo e o JS extrai via DOMParser
            return new Response($html);
        }

        if ($form->isSubmitted() && $form->isValid()) {
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
                $nomes    = [];
                $dir      = $this->getParameter('kernel.project_dir') . '/public/uploads/oops';
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
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

            $this->addFlash('success', 'Solicitação enviada com sucesso! Você receberá uma confirmação por e-mail em breve.');
            return $this->redirectToRoute('solicitacao_confirmacao', ['id' => $solicitacao->getId()]);
        }

        if ($form->isSubmitted() && !$form->isValid()) {
            try { $tipoAtual = $solicitacao->getTipo(); } catch (\Throwable) { $tipoAtual = null; }
            $abaAtual = 'nova';
            $erros = [];
            foreach ($form->getErrors(true) as $erro) {
                $erros[] = $erro->getMessage();
            }
            if (!empty($erros)) {
                $this->addFlash('danger', 'Corrija os erros no formulário: ' . implode(' | ', array_unique($erros)));
            }
        }

        $historico = [];
        if ($user && $abaAtual === 'historico') {
            $historico = $this->solicitacaoRepo->findByEmail($user->getEmail());
        }

        $gestaoLista  = [];
        $contadores   = [];
        $filtroStatus = null;
        $filtroTipo   = null;

        if ($isAdmin && $abaAtual === 'gestao') {
            $filtroStatus = $request->query->get('status') ?: null;
            $filtroTipo   = $request->query->get('tipo')   ?: null;
            $gestaoLista  = $this->solicitacaoRepo->findParaGestao($filtroStatus, $filtroTipo);
            $contadores   = $this->solicitacaoRepo->countByStatus();
        }

        return $this->render('solicitacao/hub.html.twig', [
            'form'         => $form,
            'tipoAtual'    => $tipoAtual,
            'abaAtual'     => $abaAtual,
            'isAdmin'      => $isAdmin,
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

    /**
     * Muda o status de uma solicitação.
     * Quando o desfecho é final (resolvida/negada/cancelada) o service
     * preenche resolvidaPor, resolvidaEm e notaResolucao automaticamente.
     */
    #[Route('/{id}/resolver', name: 'solicitacao_resolver', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resolver(Solicitacao $solicitacao, Request $request): Response
    {
        $this->denyAccessUnlessGranted('SOLICITACAO_RESOLVER', $solicitacao);

        if (!$this->isCsrfTokenValid('resolver_' . $solicitacao->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Aceita tanto o campo 'desfecho' (card de encerramento) quanto 'status' (mudar status intermediário)
        $novoStatus = $request->request->get('desfecho')
                   ?? $request->request->get('status');

        if (!$novoStatus || !array_key_exists($novoStatus, Solicitacao::STATUS_LABELS)) {
            $this->addFlash('danger', 'Status inválido.');
            return $this->redirectToRoute('solicitacao_detalhe', ['id' => $solicitacao->getId()]);
        }

        $nota = trim((string) $request->request->get('nota', '')) ?: null;

        $this->solicitacaoService->mudarStatus($solicitacao, $novoStatus, $this->getUser(), $nota);

        $label = Solicitacao::STATUS_LABELS[$novoStatus] ?? $novoStatus;
        $this->addFlash('success', "Solicitação atualizada para: $label.");

        return $this->redirectToRoute('solicitacao_hub', ['aba' => 'gestao']);
    }
}
