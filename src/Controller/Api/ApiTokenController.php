<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\ApiTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rotas para o usuário autenticado (sessão web) gerenciar seu token de API.
 *
 * GET  /api/meu-token          → retorna o token atual (ou null)
 * POST /api/token/gerar        → gera / substitui o token
 * POST /api/token/revogar      → apaga o token
 */
#[Route('/api', name: 'api_token_')]
#[IsGranted('ROLE_USER')]
final class ApiTokenController extends AbstractController
{
    public function __construct(
        private readonly ApiTokenService $tokenService,
    ) {}

    #[Route('/meu-token', name: 'ver', methods: ['GET'])]
    public function ver(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'token'        => $user->getApiToken(),
            'gerado_em'    => $user->getApiTokenGeneratedAt()?->format('d/m/Y H:i:s'),
            'instrucao'    => $user->getApiToken()
                ? 'Authorization: Bearer ' . $user->getApiToken()
                : 'Nenhum token ativo. Use POST /api/token/gerar para criar um.',
        ]);
    }

    #[Route('/token/gerar', name: 'gerar', methods: ['POST'])]
    public function gerar(): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $token = $this->tokenService->gerarToken($user);

        return $this->json([
            'token'     => $token,
            'gerado_em' => $user->getApiTokenGeneratedAt()->format('d/m/Y H:i:s'),
            'instrucao' => 'Authorization: Bearer ' . $token,
        ], 201);
    }

    #[Route('/token/revogar', name: 'revogar', methods: ['POST'])]
    public function revogar(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->tokenService->revogarToken($user);

        return $this->json(['mensagem' => 'Token revogado com sucesso.']);
    }
}
