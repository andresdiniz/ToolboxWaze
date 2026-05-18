<?php

namespace App\Controller;

use App\Repository\NotificacaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notificacoes')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
class NotificacaoController extends AbstractController
{
    public function __construct(
        private readonly NotificacaoRepository  $repo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'notificacao_index', methods: ['GET'])]
    public function index(): Response
    {
        $notificacoes = $this->repo->findRecentes($this->getUser(), 50);
        $this->repo->marcarTodasLidas($this->getUser());
        return $this->render('notificacao/index.html.twig', ['notificacoes' => $notificacoes]);
    }

    #[Route('/count', name: 'notificacao_count', methods: ['GET'])]
    public function count(): JsonResponse
    {
        return $this->json(['count' => $this->repo->countNaoLidas($this->getUser())]);
    }

    #[Route('/marcar-lidas', name: 'notificacao_marcar_lidas', methods: ['POST'])]
    public function marcarLidas(): JsonResponse
    {
        $this->repo->marcarTodasLidas($this->getUser());
        return $this->json(['ok' => true]);
    }
}
