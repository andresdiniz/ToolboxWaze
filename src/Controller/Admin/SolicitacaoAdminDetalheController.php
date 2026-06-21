<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Solicitacao;
use App\Entity\SolicitacaoHistorico;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Detalhe e gestão de uma Solicitação no admin.
 * Suporta tanto solicitações legadas (dados fixos) quanto dinâmicas (FormBuilder).
 */
#[Route('/admin/solicitacoes', name: 'admin_solicitacao_')]
#[IsGranted('ROLE_ADMIN')]
final class SolicitacaoAdminDetalheController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    // ── Detalhe ───────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'detalhe', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function detalhe(int $id): Response
    {
        $sol = $this->em->find(Solicitacao::class, $id)
            ?? throw $this->createNotFoundException();

        // Monta os campos para exibição
        $camposExibicao = $this->buildCamposExibicao($sol);

        return $this->render('admin/solicitacao/detalhe.html.twig', [
            'sol'            => $sol,
            'camposExibicao' => $camposExibicao,
            'statusOpcoes'   => Solicitacao::STATUS_LABELS,
        ]);
    }

    // ── Alterar status (AJAX) ─────────────────────────────────────────────────

    #[Route('/{id}/status', name: 'status', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function status(int $id, Request $req): JsonResponse
    {
        $sol = $this->em->find(Solicitacao::class, $id)
            ?? throw $this->createNotFoundException();

        $novoStatus = $req->request->get('status', '');
        if (!isset(Solicitacao::STATUS_LABELS[$novoStatus])) {
            return new JsonResponse(['ok' => false, 'msg' => 'Status inválido.'], 400);
        }

        $statusAnterior = $sol->getStatus();
        $nota           = trim((string) $req->request->get('nota', ''));

        $sol->setStatus($novoStatus);

        if (in_array($novoStatus, Solicitacao::STATUS_FINAIS, true)) {
            /** @var User $user */
            $user = $this->getUser();
            $sol->setResolvidaPor($user)
                ->setResolvidaEm(new \DateTimeImmutable());
        }

        if ($nota !== '') {
            $sol->setNotaResolucao($nota);
        }

        // Histórico
        $hist = new SolicitacaoHistorico();
        $hist->setSolicitacao($sol)
             ->setAutor($this->getUser())
             ->setAcao('status_alterado')
             ->setDescricao(sprintf(
                 'Status alterado de "%s" para "%s"%s.',
                 Solicitacao::STATUS_LABELS[$statusAnterior] ?? $statusAnterior,
                 Solicitacao::STATUS_LABELS[$novoStatus],
                 $nota !== '' ? ' — Nota: ' . $nota : ''
             ));
        $sol->addHistorico($hist);

        $this->em->flush();

        return new JsonResponse([
            'ok'         => true,
            'statusLabel' => Solicitacao::STATUS_LABELS[$novoStatus],
            'statusCor'   => Solicitacao::STATUS_CORES[$novoStatus],
        ]);
    }

    // ── Adicionar comentário (AJAX) ───────────────────────────────────────────

    #[Route('/{id}/comentar', name: 'comentar', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function comentar(int $id, Request $req): JsonResponse
    {
        $sol  = $this->em->find(Solicitacao::class, $id)
            ?? throw $this->createNotFoundException();

        $texto = trim((string) $req->request->get('texto', ''));
        if ($texto === '') {
            return new JsonResponse(['ok' => false, 'msg' => 'Comentário vazio.'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();

        $hist = new SolicitacaoHistorico();
        $hist->setSolicitacao($sol)
             ->setAutor($user)
             ->setAcao('comentario')
             ->setDescricao($texto);
        $sol->addHistorico($hist);

        $this->em->flush();

        return new JsonResponse([
            'ok'     => true,
            'autor'  => $user->getEmail(),
            'texto'  => $texto,
            'data'   => (new \DateTimeImmutable())->format('d/m/Y H:i'),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Retorna array de ['label' => ..., 'valor' => ...] para exibir no detalhe.
     * Funciona para solicitações legadas e dinâmicas.
     */
    private function buildCamposExibicao(Solicitacao $sol): array
    {
        $out = [];

        if ($sol->isDinamico() && $sol->getFormulario() !== null) {
            // Form dinâmico: usa os campos do FormBuilder como labels
            $dados = $sol->getDadosDinamicos() ?? [];
            foreach ($sol->getFormulario()->getCampos() as $campo) {
                $val = $dados[$campo->getChave()] ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                if (is_array($val)) {
                    $val = implode(', ', $val);
                }
                $out[] = [
                    'label' => $campo->getLabel(),
                    'valor' => (string) $val,
                    'tipo'  => $campo->getTipo(),
                ];
            }
        } else {
            // Legado: exibe os dados fixos do campo `dados`
            foreach ($sol->getDados() ?? [] as $key => $val) {
                if (is_array($val)) {
                    $val = implode(', ', $val);
                }
                $out[] = [
                    'label' => ucfirst(str_replace('_', ' ', $key)),
                    'valor' => (string) $val,
                    'tipo'  => 'text',
                ];
            }
        }

        return $out;
    }
}
