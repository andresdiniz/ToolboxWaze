<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Gera e valida tokens de API derivados do username do usuário.
 *
 * Algoritmo: HMAC-SHA256(API_TOKEN_SECRET, username)
 *
 * Propriedades:
 *   - Único por usuário (cada username gera um token diferente)
 *   - Determinístico (o mesmo username sempre gera o mesmo token)
 *   - Revogável trocando o API_TOKEN_SECRET no .env
 *   - Sem banco de dados adicional
 */
final class ApiTokenService
{
    public function __construct(
        #[Autowire(env: 'API_TOKEN_SECRET')]
        private readonly string $secret,
    ) {}

    /**
     * Gera o token para um username.
     */
    public function generateForUsername(string $username): string
    {
        return hash_hmac('sha256', $username, $this->secret);
    }

    /**
     * Gera o token para um objeto User do Symfony.
     */
    public function generateForUser(UserInterface $user): string
    {
        return $this->generateForUsername($user->getUserIdentifier());
    }

    /**
     * Valida um token recebido no header Authorization.
     * Retorna o username se válido, null caso contrário.
     *
     * A comparação é feita via hash_equals() para evitar timing attacks.
     */
    public function validateToken(string $receivedToken, string $username): bool
    {
        $expected = $this->generateForUsername($username);
        return hash_equals($expected, $receivedToken);
    }

    /**
     * Extrai o Bearer token do header Authorization.
     * Retorna null se o header estiver ausente ou mal formatado.
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
