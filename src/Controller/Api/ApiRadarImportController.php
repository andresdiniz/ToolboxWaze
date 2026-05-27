<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\ApiTokenService;
use App\Service\RadarImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/radares', name: 'api_radar_')]
final class ApiRadarImportController extends AbstractController
{
    private const MAX_BATCH = 500;

    public function __construct(
        private readonly RadarImportService $importService,
        private readonly ApiTokenService    $tokenService,
    ) {}

    #[Route('/importar', name: 'importar', methods: ['POST'])]
    public function importar(Request $req): JsonResponse
    {
        $rawToken = $this->tokenService->extractBearerToken(
            $req->headers->get('Authorization', '')
        );
        if ($rawToken === null || $this->tokenService->resolveUser($rawToken) === null) {
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
}
