<?php

namespace App\Controller;

use App\Entity\Solicitacao;
use App\Service\SolicitacaoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/solicitacoes/{id}/comentarios', requirements: ['id' => '\\d+'])]
class SolicitacaoComentarioController extends AbstractController
{
    public function __construct(private readonly SolicitacaoService $service) {}

    #[Route('', name: 'solicitacao_comentario_add', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function add(Solicitacao $solicitacao, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('comment_' . $solicitacao->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token inválido.');
            return $this->redirectToRoute('solicitacao_show', ['id' => $solicitacao->getId()]);
        }

        $mensagem = trim((string) $request->request->get('mensagem', ''));
        if (mb_strlen($mensagem) < 3 || mb_strlen($mensagem) > 2000) {
            $this->addFlash('danger', 'Mensagem deve ter entre 3 e 2000 caracteres.');
            return $this->redirectToRoute('solicitacao_show', ['id' => $solicitacao->getId()]);
        }

        $interno = (bool) $request->request->get('interno', false);
        // Apenas responsáveis podem marcar como interno
        if ($interno && !$this->isGranted('ROLE_ADMIN')) {
            $interno = false;
        }

        $this->service->adicionarComentario(
            $solicitacao,
            $mensagem,
            $this->getUser(),
            null,
            $interno
        );

        $this->addFlash('success', 'Comentário adicionado.');
        return $this->redirectToRoute('solicitacao_show', ['id' => $solicitacao->getId(), '_fragment' => 'chat']);
    }

    #[Route('/status', name: 'solicitacao_status_change', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function changeStatus(Solicitacao $solicitacao, Request $request): Response
    {
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
