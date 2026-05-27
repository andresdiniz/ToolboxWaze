<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\ApiTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Permite que o usuário autenticado (via sessão web) consulte seu token de API.
 *
 * GET /api/meu-token
 * Requer: usuário logado no site (ROLE_USER)
 */
#[Route('/api', name: 'api_token_')]
final class ApiTokenController extends AbstractController
{
    public function __construct(
        private readonly ApiTokenService $tokenService,
    ) {}

    #[Route('/meu-token', name: 'meu_token', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function meuToken(): JsonResponse
    {
        $user  = $this->getUser();
        $token = $this->tokenService->generateForUser($user);

        return $this->json([
            'username' => $user->getUserIdentifier(),
            'token'    => $token,
            'uso'      => 'Authorization: Bearer ' . $token,
        ]);
    }
}
