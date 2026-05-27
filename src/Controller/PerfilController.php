<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ApiTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/perfil', name: 'perfil_')]
#[IsGranted('ROLE_USER')]
final class PerfilController extends AbstractController
{
    public function __construct(
        private readonly ApiTokenService $tokenService,
    ) {}

    /** Página de gerenciamento do token de API */
    #[Route('/api-token', name: 'api_token', methods: ['GET'])]
    public function apiToken(): Response
    {
        return $this->render('perfil/api_token.html.twig');
    }

    /** Gera ou substitui o token */
    #[Route('/api-token/gerar', name: 'api_token_gerar', methods: ['POST'])]
    public function gerarToken(Request $req): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('api_token_gerar', $req->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('perfil_api_token');
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->tokenService->gerarToken($user);

        $this->addFlash('success', 'Novo token gerado com sucesso!');
        return $this->redirectToRoute('perfil_api_token');
    }

    /** Revoga o token */
    #[Route('/api-token/revogar', name: 'api_token_revogar', methods: ['POST'])]
    public function revogarToken(Request $req): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('api_token_revogar', $req->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('perfil_api_token');
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->tokenService->revogarToken($user);

        $this->addFlash('success', 'Token revogado. Gere um novo quando precisar.');
        return $this->redirectToRoute('perfil_api_token');
    }
}
