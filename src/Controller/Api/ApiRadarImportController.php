<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use App\Service\RadarImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint de importação bulk de radares.
 *
 * POST /api/radares/importar
 * Authorization: Bearer <token gerado em /api/meu-token>
 * Content-Type: application/json
 *
 * Body: array JSON de até 500 radares.
 * Retorna: { created, updated, skipped, errors[] }
 */
#[Route('/api/radares', name: 'api_radar_')]
final class ApiRadarImportController extends AbstractController
{
    private const MAX_BATCH = 500;

    public function __construct(
        private readonly RadarImportService $importService,
        private readonly ApiTokenService    $tokenService,
        private readonly UserRepository     $userRepository,
    ) {}

    #[Route('/importar', name: 'importar', methods: ['POST'])]
    public function importar(Request $req): JsonResponse
    {
        $user = $this->resolveUserFromToken($req);
        if ($user === null) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($req->getContent(), true);

        if (!is_array($data) || $data === []) {
            return $this->json(['error' => 'Body deve ser um array JSON não vazio.'], 400);
        }

        if (!array_is_list($data)) {
            return $this->json(['error' => 'Body deve ser um array indexado (lista), não um objeto.'], 400);
        }

        if (count($data) > self::MAX_BATCH) {
            return $this->json([
                'error' => sprintf('Máximo de %d registros por requisição. Enviados: %d.', self::MAX_BATCH, count($data)),
            ], 422);
        }

        $result = $this->importService->processBatch($data);
        $status = $result['errors'] === [] ? 200 : 207;

        return $this->json($result, $status);
    }

    // ── Helper ────────────────────────────────────────────────────────────

    private function resolveUserFromToken(Request $req): ?object
    {
        $token = $this->tokenService->extractBearerToken(
            $req->headers->get('Authorization', '')
        );
        if ($token === null) {
            return null;
        }

        // Percorre todos os usuários com permissão de API e valida o token
        // Performance: apenas usuários com ROLE_API_IMPORT são verificados
        $users = $this->userRepository->findByRole('ROLE_API_IMPORT');
        foreach ($users as $user) {
            if ($this->tokenService->validateToken($token, $user->getUserIdentifier())) {
                return $user;
            }
        }

        return null;
    }
}
