<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BrazilianStateRepository;
use App\Repository\ConsultaPublicaRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/consultar')]
final class ConsultaPublicaController extends AbstractController
{
    public function __construct(
        private readonly ConsultaPublicaRepository $repository,
        private readonly BrazilianStateRepository $stateRepository,
    ) {
    }

    #[Route('', name: 'consulta_publica', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('consulta_publica/index.html.twig', [
            'states' => $this->stateRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/api/municipios', name: 'consulta_publica_municipios', methods: ['GET'])]
    public function municipios(Request $request): JsonResponse
    {
        return $this->json([
            'items' => $this->repository->findMunicipios(
                (string) $request->query->get('tipo', ''),
                (string) $request->query->get('uf', '')
            ),
        ]);
    }

    #[Route('/api/{tipo}', name: 'consulta_publica_search', methods: ['GET'], requirements: ['tipo' => 'radar|escola|posto'])]
    public function search(string $tipo, Request $request): JsonResponse
    {
        $page = min(10000, max(1, $request->query->getInt('page', 1)));
        $limit = min(50, max(1, $request->query->getInt('limit', 20)));
        $filters = [
            'uf' => mb_substr(trim((string) $request->query->get('uf', '')), 0, 2),
            'municipio' => mb_substr(trim((string) $request->query->get('municipio', '')), 0, 120),
            'q' => mb_substr(trim((string) $request->query->get('q', '')), 0, 100),
        ];

        return $this->json([
            ...$this->repository->search($tipo, $filters, $page, $limit),
            'source' => match ($tipo) {
                'radar' => ['name' => 'PSIE/INMETRO', 'updatedAt' => null],
                'escola' => ['name' => 'Censo Escolar/INEP', 'updatedAt' => null],
                'posto' => ['name' => 'ANP — revendedores de combustíveis', 'updatedAt' => null],
            },
        ]);
    }
}
