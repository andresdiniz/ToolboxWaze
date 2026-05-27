<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\RadarImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint de importação bulk de radares.
 *
 * POST /api/radares/importar
 * Authorization: Bearer <API_IMPORT_TOKEN>
 * Content-Type: application/json
 *
 * Body: array JSON de até 500 radares (ver RadarImportService::FIELDS).
 * Retorna: { created, updated, skipped, errors[] }
 */
#[Route('/api/radares', name: 'api_radar_')]
final class ApiRadarImportController extends AbstractController
{
    private const MAX_BATCH = 500;

    public function __construct(
        private readonly RadarImportService $importService,
        #[Autowire(env: 'API_IMPORT_TOKEN')]
        private readonly string $apiToken,
    ) {}

    #[Route('/importar', name: 'importar', methods: ['POST'])]
    public function importar(Request $req): JsonResponse
    {
        // ── Autenticação Bearer ──────────────────────────────────────────────
        $auth = $req->headers->get('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ') || substr($auth, 7) !== $this->apiToken) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        // ── Parse do body ───────────────────────────────────────────────────
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

        // ── Processamento ───────────────────────────────────────────────────
        $result = $this->importService->processBatch($data);

        $status = $result['errors'] === [] ? 200 : 207; // 207 = Multi-Status (parcialmente OK)

        return $this->json($result, $status);
    }
}
