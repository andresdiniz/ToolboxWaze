<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\CoberturaRankingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CoberturaRankingController extends AbstractController
{
    #[Route('/cobertura', name: 'cobertura_index', methods: ['GET'])]
    #[Route('/cobertura/rankings', name: 'cobertura_rankings', methods: ['GET'])]
    public function index(Request $request, CoberturaRankingService $service): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw $this->createAccessDeniedException();
        $tipo = $request->query->getString('tipo');
        $uf = strtoupper(trim($request->query->getString('uf')));
        $ordem = $request->query->getString('ordem', 'percentual');
        $dados = $service->dados($user, $tipo, $uf, $ordem);
        $pages = max(1, (int) ceil(count($dados['editores']) / 25));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));
        $offset = ($page - 1) * 25;
        $dados['editores'] = array_slice($dados['editores'], $offset, 25);
        $response = $this->render('cobertura/rankings.html.twig', $dados + [
            'filters' => compact('tipo', 'uf', 'ordem'), 'page' => $page, 'pages' => $pages,
            'offset' => $offset, 'tipos' => $service->tipos($user),
            'ufs' => array_values(array_filter(User::ALL_UFS, $user->canAccessUf(...))),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
