<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gerencia tokens de API persistidos no banco de dados.
 *
 * - Token: 64 hex chars (256 bits de entropia)
 * - Único por usuário, armazenado em claro na coluna api_token
 * - Revogar = setar NULL (o usuário pode gerar um novo)
 * - Validação: busca direto pelo token no banco (lookup O(1) via índice UNIQUE)
 */
final class ApiTokenService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository         $userRepository,
    ) {}

    /**
     * Gera um novo token para o usuário e persiste.
     * Se já existia um token, ele é substituído (revogando o anterior).
     */
    public function gerarToken(User $user): string
    {
        $token = bin2hex(random_bytes(32)); // 64 hex chars

        $user->setApiToken($token);
        $user->setApiTokenGeneratedAt(new \DateTimeImmutable());

        $this->em->persist($user);
        $this->em->flush();

        return $token;
    }

    /**
     * Revoga o token do usuário (seta NULL).
     */
    public function revogarToken(User $user): void
    {
        $user->setApiToken(null);
        $user->setApiTokenGeneratedAt(null);

        $this->em->persist($user);
        $this->em->flush();
    }

    /**
     * Valida o Bearer token recebido e retorna o User correspondente.
     * Retorna null se o token não existir ou estiver revogado.
     */
    public function resolveUser(string $bearerToken): ?User
    {
        if (strlen($bearerToken) !== 64) {
            return null;
        }

        return $this->userRepository->findOneBy(['apiToken' => $bearerToken]);
    }

    /**
     * Extrai o Bearer token do header Authorization.
     */
    public function extractBearerToken(string $authorizationHeader): ?string
    {
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($authorizationHeader, 7));
        return $token !== '' ? $token : null;
    }
}
