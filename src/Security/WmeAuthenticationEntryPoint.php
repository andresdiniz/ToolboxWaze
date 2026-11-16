<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class WmeAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(
        Request $request,
        ?AuthenticationException $authException = null
    ): Response {
        return new JsonResponse(
            [
                'codigo' => 'nao_autenticado',
                'message' => 'Token pessoal obrigatório.',
            ],
            Response::HTTP_UNAUTHORIZED,
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
