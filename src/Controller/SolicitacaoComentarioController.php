<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Solicitacao;
use App\Security\SolicitacaoVoter;
use App\Service\SolicitacaoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/solicitacoes/{id}/comentarios', requirements: ['id' => '\\d+'])]
class SolicitacaoComentarioController extends AbstractController
{
    public function __construct(private readonly SolicitacaoService $service) {}

    // -------------------------------------------------------------------------
    // POST /solicitacoes/{id}/comentarios
    // -------------------------------------------------------------------------
    #[Route('', name: 'solicitacao_comentario_add', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function add(Solicitacao $solicitacao, Request $request): Response
    {
        // Verifica permissão: solicitante OU responsável do tipo
        $this->denyAccessUnlessGranted(SolicitacaoVoter::COMENTAR, $solicitacao);

        if (!$this->isCsrfTokenValid('comment_' . $solicitacao->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token inválido.');
            return $this->redirectToRoute('solicitacao_show', ['id' => $solicitacao->getId()]);
        }

        $mensagem = trim((string) $request->request->get('mensagem', ''));
        if (mb_strlen($mensagem) < 3 || mb_strlen($mensagem) > 2000) {
            $this->addFlash('danger', 'Mensagem deve ter entre 3 e 2000 caracteres.');
            return $this->redirectToRoute('solicitacao_show', ['id' => $solicitacao->getId()]);
        }

        // Apenas responsáveis/admins podem marcar como interno
        $podeInterno = $this->isGranted(SolicitacaoVoter::VER_INTERNO, $solicitacao);
        $interno = $podeInterno && (bool) $request->request->get('interno', false);

        $this->service->adicionarComentario(
            $solicitacao,
            $mensagem,
            $this->getUser(),
            null,
            $interno
        );

        $this->addFlash('success', 'Comentário adicionado.');
        return $this->redirectToRoute('solicitacao_show', [
            'id'       => $solicitacao->getId(),
            '_fragment' => 'chat',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /solicitacoes/{id}/comentarios/status
    // -------------------------------------------------------------------------
    #[Route('/status', name: 'solicitacao_status_change', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function changeStatus(Solicitacao $solicitacao, Request $request): Response
    {
        // Apenas responsáveis do tipo e admins podem mudar o status
        $this->denyAccessUnlessGranted(SolicitacaoVoter::MUDAR_STATUS, $solicitacao);

        if (!$this->isCsrfTokenValid('status_' . $solicitacao->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token inválido.');
            return $this->redirectToRoute('solicitacao_show', ['id' => $solicitacao->getId()]);
        }

        $novoStatus = $request->request->get('status', '');
        $nota       = trim((string) $request->request->get('nota', ''));

        if (!array_key_exists($novoStatus, Solicitacao::STATUS_LABELS)) {
            $this->addFlash('danger', 'Status inválido.');
            return $this->redirectToRoute('solicitacao_show', ['id' => $solicitacao->getId()]);
        }

        $this->service->mudarStatus($solicitacao, $novoStatus, $this->getUser(), $nota ?: null);

        $this->addFlash('success', 'Status atualizado para "' . Solicitacao::STATUS_LABELS[$novoStatus] . '".');
        return $this->redirectToRoute('solicitacao_show', ['id' => $solicitacao->getId()]);
    }
}
