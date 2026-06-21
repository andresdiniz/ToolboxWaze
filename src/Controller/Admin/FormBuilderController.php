<?php

namespace App\Controller\Admin;

use App\Entity\FormBuilder;
use App\Entity\FormBuilderCampo;
use App\Entity\FormBuilderResposta;
use App\Repository\FormBuilderRepository;
use App\Repository\FormBuilderRespostaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin4/form-builder', name: 'admin4_form_builder_')]
#[IsGranted('ROLE_ADMIN4')]
final class FormBuilderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly FormBuilderRepository       $formRepo,
        private readonly FormBuilderRespostaRepository $respostaRepo,
        private readonly SluggerInterface            $slugger,
    ) {}

    // ── Lista de formulários ───────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin4/form_builder/index.html.twig', [
            'forms' => $this->formRepo->findBy([], ['criadoEm' => 'DESC']),
        ]);
    }

    // ── Novo formulário ────────────────────────────────────────────────────────

    #[Route('/novo', name: 'novo', methods: ['GET', 'POST'])]
    public function novo(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->salvar($request, new FormBuilder());
        }

        return $this->render('admin4/form_builder/editor.html.twig', [
            'form'   => null,
            'campos' => [],
            'titulo' => 'Novo Formulário',
        ]);
    }

    // ── Editar formulário ──────────────────────────────────────────────────────

    #[Route('/{id}/editar', name: 'editar', methods: ['GET', 'POST'])]
    public function editar(int $id, Request $request): Response
    {
        $form = $this->formRepo->find($id) ?? throw $this->createNotFoundException();

        if ($request->isMethod('POST')) {
            return $this->salvar($request, $form);
        }

        return $this->render('admin4/form_builder/editor.html.twig', [
            'form'   => $form,
            'campos' => $form->getCampos()->toArray(),
            'titulo' => 'Editar: ' . $form->getNome(),
        ]);
    }

    // ── Lógica de persistência (novo + editar) ─────────────────────────────────

    private function salvar(Request $request, FormBuilder $form): Response
    {
        $data = $request->request->all();

        $nome = trim($data['nome'] ?? '');
        if (!$nome) {
            $this->addFlash('error', 'Nome do formulário é obrigatório.');
            return $this->redirectToRoute('admin4_form_builder_index');
        }

        $form->setNome($nome);
        $form->setDescricao(trim($data['descricao'] ?? '') ?: null);
        $form->setAtivo(isset($data['ativo']));
        $form->setAtualizadoEm(new \DateTimeImmutable());

        // Configurações globais
        $form->setConfiguracoes([
            'redirect_url'      => trim($data['redirect_url'] ?? '') ?: null,
            'success_message'   => trim($data['success_message'] ?? '') ?: 'Obrigado pelo envio!',
            'email_notificacao' => trim($data['email_notificacao'] ?? '') ?: null,
            'mostrar_para'      => $data['mostrar_para'] ?? 'todos',   // todos | logado | visitante
            'limite_envios'     => (int)($data['limite_envios'] ?? 0), // 0 = ilimitado
            'expira_em'         => trim($data['expira_em'] ?? '') ?: null,
        ]);

        // Slug
        if (!$form->getSlug()) {
            $base = strtolower((string)$this->slugger->slug($nome));
            $slug = $base;
            $i    = 1;
            while ($this->formRepo->findOneBy(['slug' => $slug])) {
                $slug = $base . '-' . $i++;
            }
            $form->setSlug($slug);
        }

        if (!$form->getCriadoPor()) {
            $form->setCriadoPor($this->getUser());
        }

        // ── Campos ────────────────────────────────────────────────────────────
        // Remove campos antigos
        foreach ($form->getCampos() as $c) {
            $form->removeCampo($c);
            $this->em->remove($c);
        }

        $camposJson = json_decode($data['campos_json'] ?? '[]', true) ?? [];
        foreach ($camposJson as $i => $cd) {
            $campo = new FormBuilderCampo();
            $campo->setChave($cd['chave'] ?? 'campo_' . $i);
            $campo->setLabel($cd['label'] ?? '');
            $campo->setTipo($cd['tipo'] ?? 'text');
            $campo->setOrdem($i);
            $campo->setObrigatorio((bool)($cd['obrigatorio'] ?? false));
            $campo->setPlaceholder($cd['placeholder'] ?? null);
            $campo->setAjuda($cd['ajuda'] ?? null);
            $campo->setValorPadrao($cd['valor_padrao'] ?? null);

            // Extras: opcoes, mask, validacao, condicional, col, content, etc.
            $extras = [];
            foreach (['opcoes','min','max','step','accept','multiple','mask','validacao','condicional','css_class','col','content','nivel'] as $key) {
                if (isset($cd[$key]) && $cd[$key] !== '' && $cd[$key] !== null) {
                    $extras[$key] = $cd[$key];
                }
            }
            $campo->setOpcoes($extras ?: null);

            $form->addCampo($campo);
        }

        $this->em->persist($form);
        $this->em->flush();

        $this->addFlash('success', 'Formulário salvo com sucesso.');
        return $this->redirectToRoute('admin4_form_builder_editar', ['id' => $form->getId()]);
    }

    // ── Excluir formulário ─────────────────────────────────────────────────────

    #[Route('/{id}/excluir', name: 'excluir', methods: ['POST'])]
    public function excluir(int $id, Request $request): Response
    {
        $form = $this->formRepo->find($id) ?? throw $this->createNotFoundException();
        $this->em->remove($form);
        $this->em->flush();
        $this->addFlash('success', 'Formulário excluído.');
        return $this->redirectToRoute('admin4_form_builder_index');
    }

    // ── Respostas de um formulário ─────────────────────────────────────────────

    #[Route('/{id}/respostas', name: 'respostas', methods: ['GET'])]
    public function respostas(int $id): Response
    {
        $form      = $this->formRepo->find($id) ?? throw $this->createNotFoundException();
        $respostas = $this->respostaRepo->findByFormulario($id);

        return $this->render('admin4/form_builder/respostas.html.twig', [
            'form'      => $form,
            'respostas' => $respostas,
        ]);
    }

    // ── Alterar status de uma resposta (AJAX) ──────────────────────────────────

    #[Route('/resposta/{id}/status', name: 'resposta_status', methods: ['POST'])]
    public function respostaStatus(int $id, Request $request): JsonResponse
    {
        $resposta = $this->em->find(FormBuilderResposta::class, $id)
            ?? throw $this->createNotFoundException();

        $status = $request->request->get('status', 'pendente');
        if (!\in_array($status, ['pendente', 'aprovado', 'rejeitado', 'arquivado'])) {
            return new JsonResponse(['ok' => false, 'msg' => 'Status inválido'], 400);
        }

        $resposta->setStatus($status);
        $resposta->setNotaAdmin($request->request->get('nota'));
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    // ── Preview de um formulário ───────────────────────────────────────────────

    #[Route('/{id}/preview', name: 'preview', methods: ['GET'])]
    public function preview(int $id): Response
    {
        $form = $this->formRepo->find($id) ?? throw $this->createNotFoundException();

        return $this->render('form_builder/render.html.twig', [
            'form'    => $form,
            'campos'  => $form->getCampos()->toArray(),
            'preview' => true,
        ]);
    }

    // ── Exportar respostas como CSV ────────────────────────────────────────────

    #[Route('/{id}/exportar-csv', name: 'exportar_csv', methods: ['GET'])]
    public function exportarCsv(int $id): Response
    {
        $form      = $this->formRepo->find($id) ?? throw $this->createNotFoundException();
        $respostas = $this->respostaRepo->findByFormulario($id);

        $headers = array_map(fn($c) => $c->getLabel(), $form->getCampos()->toArray());
        $chaves  = array_map(fn($c) => $c->getChave(), $form->getCampos()->toArray());

        $csv = implode(';', array_merge(['UUID', 'Data', 'Status', 'IP'], $headers)) . "\n";

        foreach ($respostas as $r) {
            $row = [
                $r->getSubmissaoUuid(),
                $r->getCriadoEm()->format('d/m/Y H:i'),
                $r->getStatus(),
                $r->getIp() ?? '',
            ];
            foreach ($chaves as $ch) {
                $val = $r->getDados()[$ch] ?? '';
                if (is_array($val)) $val = implode('|', $val);
                $row[] = '"' . str_replace('"', '""', (string)$val) . '"';
            }
            $csv .= implode(';', $row) . "\n";
        }

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="respostas-' . $form->getSlug() . '.csv"',
        ]);
    }
}
