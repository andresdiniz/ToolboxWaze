<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ApiTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile/api-token', name: 'profile_api_token_')]
#[IsGranted('ROLE_USER')]
final class ProfileApiController extends AbstractController
{
    public function __construct(
        private readonly ApiTokenService $apiTokenService,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('profile/api_token.html.twig', [
            'newToken' => null,
            'tokenGeneratedAt' => $user->getApiTokenGeneratedAt(),
        ]);
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->isCsrfTokenValid('api_token_generate', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $token = $this->apiTokenService->generate($user);

        $this->addFlash('success', 'Novo token de API gerado. Guarde-o agora: ele não será exibido novamente.');

        return $this->render('profile/api_token.html.twig', [
            'newToken' => $token,
            'tokenGeneratedAt' => $user->getApiTokenGeneratedAt(),
        ]);
    }

    #[Route('/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->isCsrfTokenValid('api_token_revoke', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->apiTokenService->revoke($user);

        $this->addFlash('success', 'Token de API revogado.');

        return $this->redirectToRoute('profile_api_token');
    }
}
