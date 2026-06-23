<?php

namespace App\Controller;

use App\Repository\NotificacaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
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

    // ─────────────────────────────────────────────────────────────────────
    //  #12 – Web Push API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Armazena a subscription do browser para Web Push.
     * Payload JSON: { endpoint, keys: { p256dh, auth } }
     */
    #[Route('/push/subscribe', name: 'notificacao_push_subscribe', methods: ['POST'])]
    public function pushSubscribe(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['endpoint']) || empty($data['keys']['p256dh']) || empty($data['keys']['auth'])) {
            return $this->json(['ok' => false, 'error' => 'Payload inválido'], 400);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $user->setPushSubscription(json_encode($data));
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * Remove a subscription Web Push do usuário (unsubscribe do browser).
     */
    #[Route('/push/unsubscribe', name: 'notificacao_push_unsubscribe', methods: ['POST'])]
    public function pushUnsubscribe(): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $user->setPushSubscription(null);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * Retorna a VAPID public key para o cliente JS.
     */
    #[Route('/push/vapid-public-key', name: 'notificacao_push_vapid_key', methods: ['GET'])]
    public function vapidPublicKey(): JsonResponse
    {
        return $this->json([
            'publicKey' => $_ENV['VAPID_PUBLIC_KEY'] ?? '',
        ]);
    }
}
