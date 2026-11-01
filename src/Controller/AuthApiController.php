<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AuthApiController extends AbstractController
{
    #[Route('/api/auth/validate', name: 'api_auth_validate', methods: ['GET'])]
    public function validate(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['valid' => false, 'error' => 'Token inválido.'], 401);
        }

        return $this->json([
            'valid' => true,
            'username' => $user->getWazeNickname() ?? $user->getUserIdentifier(),
            'nome' => $user->getName(),
        ]);
    }
}
