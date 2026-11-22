<?php

namespace App\Controller\Admin;

use App\Entity\FormBuilder;
use App\Entity\FormBuilderCampo;
use App\Entity\FormBuilderResposta;
use App\Entity\Solicitacao;
use App\Repository\FormBuilderRepository;
use App\Repository\FormBuilderRespostaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestão de formulários (lista, ativar/desativar, duplicar, excluir, respostas).
 *
 * A edição de campos e configurações é feita no Estúdio (FormBuilderStudioController):
 * ele preserva as regras de publicação no WME e valida a definição. As rotas antigas
 * de "novo" e "editar" continuam existindo, mas só redirecionam para o Estúdio.
 *
 * Toda ação que altera dados (POST) exige token CSRF.
 */
#[Route('/admin4/form-builder', name: 'admin4_form_builder_')]
#[IsGranted('ROLE_ADMIN')]
final class FormBuilderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface        $em,
        private readonly FormBuilderRepository         $formRepo,
        private readonly FormBuilderRespostaRepository $respostaRepo,
    ) {}

    // ── Lista de formulários ───────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $solicitacoes = $this->contagemPorFormulario(Solicitacao::class, 's');
        $respostas    = $this->contagemPorFormulario(FormBuilderResposta::class, 'r');

        $rows = [];

        foreach ($this->formRepo->findBy([], ['criadoEm' => 'DESC']) as $form) {
            $id     = (int) $form->getId();
            $nSol   = $solicitacoes[$id] ?? 0;
            $nResp  = $respostas[$id] ?? 0;
            $config = $form->getConfiguracoes() ?? [];

            $rows[] = [
                'form'             => $form,
                'solicitacoes'     => $nSol,
                'respostas'        => $nResp,
                'publicado'        => ($config['wme_api_enabled'] ?? false) === true,
                'motivoSemExcluir' => $this->motivoSemExcluir($form, $nSol, $nResp),
            ];
        }

        return $this->render('admin4/form_builder/index.html.twig', ['rows' => $rows]);
    }

    // ── Novo / Editar: o Estúdio é o único editor ──────────────────────────────

    #[Route('/novo', name: 'novo', methods: ['GET'])]
    public function novo(): Response
    {
        return $this->redirectToRoute('admin4_form_studio_novo');
    }

    #[Route('/{id}/editar', name: 'editar', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function editar(int $id): Response
    {
        return $this->redirectToRoute('admin4_form_studio_editar', ['id' => $id]);
    }

    // ── Ativar / desativar (arquivar) ──────────────────────────────────────────

    #[Route('/{id}/alternar-ativo', name: 'alternar_ativo', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function alternarAtivo(int $id, Request $request): Response
    {
        $form = $this->formRepo->find($id) ?? throw $this->createNotFoundException();
        $this->exigirCsrf($request, 'form_toggle_' . $id);

        $form->setAtivo(!$form->isAtivo());
        $form->setAtualizadoEm(new \DateTimeImmutable());
        $this->em->flush();

        if ($form->isAtivo()) {
            $this->addFlash('success', sprintf('“%s” foi reativado.', $form->getNome()));
        } else {
            $config = $form->getConfiguracoes() ?? [];
            $extra  = ($config['wme_api_enabled'] ?? false) === true
                ? ' Ele também deixou de aparecer no script WME enquanto estiver inativo.'
                : '';

            $this->addFlash('success', sprintf('“%s” foi desativado (arquivado).%s', $form->getNome(), $extra));
        }

        return $this->redirectToRoute('admin4_form_builder_index');
    }

    // ── Duplicar ───────────────────────────────────────────────────────────────

    #[Route('/{id}/duplicar', name: 'duplicar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicar(int $id, Request $request): Response
    {
        $origem = $this->formRepo->find($id) ?? throw $this->createNotFoundException();
        $this->exigirCsrf($request, 'form_dup_' . $id);

        $copia = new FormBuilder();
        $copia->setNome(mb_substr($origem->getNome(), 0, 110) . ' (cópia)');
        $copia->setDescricao($origem->getDescricao());
        $copia->setSlug($this->slugDisponivel($origem->getSlug()));
        $copia->setAtivo(false); // a cópia nasce desativada
        $copia->setAtualizadoEm(new \DateTimeImmutable());
        $copia->setCriadoPor($this->getUser());

        // Mantém regras e permissões, mas a cópia nunca nasce publicada no WME.
        $config = $origem->getConfiguracoes() ?? [];
        $config['wme_api_enabled'] = false;
        $copia->setConfiguracoes($config);

        foreach ($origem->getCampos() as $campo) {
            $novo = new FormBuilderCampo();
            $novo->setChave($campo->getChave())
                ->setLabel($campo->getLabel())
                ->setTipo($campo->getTipo())
                ->setOrdem($campo->getOrdem())
                ->setObrigatorio($campo->isObrigatorio())
                ->setPlaceholder($campo->getPlaceholder())
                ->setAjuda($campo->getAjuda())
                ->setValorPadrao($campo->getValorPadrao())
                ->setOpcoes($campo->getOpcoes());

            $copia->addCampo($novo);
        }

        $this->em->persist($copia);
        $this->em->flush();

        $this->addFlash('success', 'Cópia criada e desativada. Revise e ative quando estiver pronta.');

        return $this->redirectToRoute('admin4_form_studio_editar', ['id' => $copia->getId()]);
    }

    // ── Excluir (só formulário desativado e sem uso) ───────────────────────────

    #[Route('/{id}/excluir', name: 'excluir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function excluir(int $id, Request $request): Response
    {
        $form = $this->formRepo->find($id) ?? throw $this->createNotFoundException();
        $this->exigirCsrf($request, 'form_delete_' . $id);

        // A regra é reavaliada aqui: esconder o botão na tela não basta.
        $motivo = $this->motivoSemExcluir(
            $form,
            $this->em->getRepository(Solicitacao::class)->count(['formulario' => $form]),
            $this->respostaRepo->count(['formulario' => $form])
        );

        if ($motivo !== null) {
            $this->addFlash('danger', 'Não foi possível excluir: ' . $motivo);

            return $this->redirectToRoute('admin4_form_builder_index');
        }

        $nome = $form->getNome();
        $this->em->remove($form);
        $this->em->flush();

        $this->addFlash('success', sprintf('“%s” foi excluído.', $nome));

        return $this->redirectToRoute('admin4_form_builder_index');
    }

    // ── Respostas de um formulário ─────────────────────────────────────────────

    #[Route('/{id}/respostas', name: 'respostas', requirements: ['id' => '\d+'], methods: ['GET'])]
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

    #[Route('/resposta/{id}/status', name: 'resposta_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function respostaStatus(int $id, Request $request): JsonResponse
    {
        $token = $request->request->get('_token');

        if (!is_string($token) || !$this->isCsrfTokenValid('form_resposta_status', $token)) {
            return new JsonResponse(['ok' => false, 'msg' => 'Token CSRF inválido'], 403);
        }

        $resposta = $this->em->find(FormBuilderResposta::class, $id)
            ?? throw $this->createNotFoundException();

        $status = $request->request->get('status', 'pendente');

        if (!\in_array($status, ['pendente', 'aprovado', 'rejeitado', 'arquivado'], true)) {
            return new JsonResponse(['ok' => false, 'msg' => 'Status inválido'], 400);
        }

        $nota = $request->request->get('nota');

        $resposta->setStatus($status);
        $resposta->setNotaAdmin(is_string($nota) ? mb_substr($nota, 0, 2000) : null);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    // ── Preview de um formulário ───────────────────────────────────────────────

    #[Route('/{id}/preview', name: 'preview', requirements: ['id' => '\d+'], methods: ['GET'])]
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

    #[Route('/{id}/exportar-csv', name: 'exportar_csv', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportarCsv(int $id): Response
    {
        $form      = $this->formRepo->find($id) ?? throw $this->createNotFoundException();
        $respostas = $this->respostaRepo->findByFormulario($id);

        $headers = array_map(fn ($c) => $this->csvSeguro((string) $c->getLabel()), $form->getCampos()->toArray());
        $chaves  = array_map(fn ($c) => $c->getChave(), $form->getCampos()->toArray());

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

                if (is_array($val)) {
                    $val = implode('|', array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $val));
                }

                $row[] = $this->csvSeguro((string) $val);
            }

            $csv .= implode(';', $row) . "\n";
        }

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="respostas-' . $form->getSlug() . '.csv"',
        ]);
    }

    // ── Auxiliares ─────────────────────────────────────────────────────────────

    private function exigirCsrf(Request $request, string $id): void
    {
        $token = $request->request->get('_token');

        if (!is_string($token) || !$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('Token CSRF inválido. Recarregue a página e tente de novo.');
        }
    }

    /** Regra única de exclusão: só inativo e sem solicitações/respostas vinculadas. */
    private function motivoSemExcluir(FormBuilder $form, int $solicitacoes, int $respostas): ?string
    {
        if ($form->isAtivo()) {
            return 'desative o formulário antes de excluir.';
        }

        if ($solicitacoes > 0 || $respostas > 0) {
            return sprintf(
                'ele tem %d solicitação(ões) e %d resposta(s) vinculadas. Mantenha-o desativado (arquivado).',
                $solicitacoes,
                $respostas
            );
        }

        return null;
    }

    /** @param class-string $entidade @return array<int,int> formulário => total */
    private function contagemPorFormulario(string $entidade, string $alias): array
    {
        $linhas = $this->em->createQuery(sprintf(
            'SELECT IDENTITY(%1$s.formulario) AS fid, COUNT(%1$s.id) AS total FROM %2$s %1$s '
            . 'WHERE %1$s.formulario IS NOT NULL GROUP BY %1$s.formulario',
            $alias,
            $entidade
        ))->getArrayResult();

        $mapa = [];

        foreach ($linhas as $linha) {
            $mapa[(int) $linha['fid']] = (int) $linha['total'];
        }

        return $mapa;
    }

    private function slugDisponivel(string $slugOrigem): string
    {
        $base = mb_substr($slugOrigem, 0, 68) . '-copia';
        $slug = $base;

        for ($i = 2; $this->formRepo->findOneBy(['slug' => $slug]) !== null; $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    /** Evita injeção de fórmulas ao abrir o CSV no Excel/Planilhas. */
    private function csvSeguro(string $valor): string
    {
        if ($valor !== '' && preg_match('/^[=+\-@\t\r]/', $valor) === 1) {
            $valor = "'" . $valor;
        }

        return '"' . str_replace('"', '""', $valor) . '"';
    }
}
