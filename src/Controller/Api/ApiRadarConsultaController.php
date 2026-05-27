<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use App\Service\RadarConsultaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/radares', name: 'api_radar_consulta_')]
final class ApiRadarConsultaController extends AbstractController
{
    private const MAX_LOTE = 100;

    public function __construct(
        private readonly RadarConsultaService $consultaService,
        private readonly ApiTokenService      $tokenService,
        private readonly UserRepository       $userRepository,
    ) {}

    #[Route('/consultar', name: 'individual', methods: ['GET'])]
    public function individual(Request $req): JsonResponse
    {
        if (!$this->autorizado($req)) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $numeroSerie   = trim((string) $req->query->get('numero_serie', ''));
        $numeroInmetro = trim((string) $req->query->get('numero_inmetro', ''));

        if ($numeroSerie === '' && $numeroInmetro === '') {
            return $this->json(['error' => 'Informe ao menos um parâmetro: numero_serie ou numero_inmetro.'], 400);
        }

        $resultado = $this->consultaService->buscar(
            $numeroSerie   !== '' ? $numeroSerie   : null,
            $numeroInmetro !== '' ? $numeroInmetro : null,
        );

        if ($resultado === null) {
            return $this->json(['error' => 'Radar não encontrado.'], 404);
        }

        return $this->json($resultado);
    }

    #[Route('/consultar/lote', name: 'lote', methods: ['POST'])]
    public function lote(Request $req): JsonResponse
    {
        if (!$this->autorizado($req)) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $body = json_decode($req->getContent(), true);

        if (!is_array($body)) {
            return $this->json(['error' => 'Body deve ser um objeto JSON.'], 400);
        }

        $numSeries   = array_filter(array_map('trim', (array) ($body['numeros_serie']   ?? [])));
        $numInmetros = array_filter(array_map('trim', (array) ($body['numeros_inmetro'] ?? [])));

        if (count($numSeries) === 0 && count($numInmetros) === 0) {
            return $this->json(['error' => 'Informe numeros_serie[] ou numeros_inmetro[] no body.'], 400);
        }

        $total = count($numSeries) + count($numInmetros);
        if ($total > self::MAX_LOTE) {
            return $this->json([
                'error' => sprintf('Máximo de %d itens por requisição. Enviados: %d.', self::MAX_LOTE, $total),
            ], 422);
        }

        $resultados = $this->consultaService->buscarLote(
            array_values($numSeries),
            array_values($numInmetros),
        );

        return $this->json(['total' => count($resultados), 'resultados' => $resultados]);
    }

    private function autorizado(Request $req): bool
    {
        $token = $this->tokenService->extractBearerToken(
            $req->headers->get('Authorization', '')
        );
        if ($token === null) {
            return false;
        }

        $users = $this->userRepository->findByRole('ROLE_API_IMPORT');
        foreach ($users as $user) {
            if ($this->tokenService->validateToken($token, $user->getUserIdentifier())) {
                return true;
            }
        }

        return false;
    }
}
