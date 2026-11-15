<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Solicitacao;
use App\Repository\SolicitacaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/wme/solicitacoes', name: 'api_wme_solicitacao_alerta_')]
final class WmeSolicitacaoAlertaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SolicitacaoRepository $solicitacoes,
    ) {
    }

    #[Route('/alteracoes', name: 'alteracoes', methods: ['GET'])]
    public function alteracoes(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if ($user === null) {
            return $this->json([
                'codigo' => 'nao_autenticado',
                'message' => 'Token pessoal obrigatório.',
            ], 401);
        }

        $since = $request->query->get('since');
        $referencia = null;

        if (is_string($since) && $since !== '') {
            try {
                $referencia = new \DateTimeImmutable($since);
            } catch (\Exception) {
                return $this->json([
                    'codigo' => 'parametro_invalido',
                    'message' => 'O parâmetro since é inválido.',
                ], 400);
            }
        }

        $builder = $this->solicitacoes->createQueryBuilder('s')
            ->where('s.solicitanteEmail = :email')
            ->setParameter('email', $user->getEmail())
            ->orderBy('s.atualizadoEm', 'ASC')
            ->setMaxResults(50);

        if ($referencia !== null) {
            $builder
                ->andWhere('s.atualizadoEm > :since')
                ->setParameter('since', $referencia);
        }

        $solicitacoes = $builder->getQuery()->getResult();

        return $this->json([
            'servidorEm' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'alteracoes' => array_map(
                fn (Solicitacao $solicitacao): array => [
                    'id' => $solicitacao->getId(),
                    'status' => $solicitacao->getStatus(),
                    'statusLabel' => $solicitacao->getStatusLabel(),
                    'atualizadoEm' => $solicitacao->getAtualizadoEm()->format(DATE_ATOM),
                    'resumo' => 'A solicitação #' . $solicitacao->getId() . ' foi atualizada.',
                    'url' => '/solicitacoes/' . $solicitacao->getId(),
                ],
                $solicitacoes
            ),
        ]);
    }
}