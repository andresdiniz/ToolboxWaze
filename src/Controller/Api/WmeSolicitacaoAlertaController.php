<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Solicitacao;
use App\Entity\User;
use App\Entity\WmeDispositivo;
use App\Exception\WmeApiException;
use App\Security\WmeDeviceAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/wme', name: 'api_wme_alerta_')]
final class WmeSolicitacaoAlertaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    private function auth(Request $r): array
    {
        $user = $this->getUser();
        $estado = $r->attributes->get(WmeDeviceAuthenticator::ATTR_ESTADO);

        if (!$user instanceof User || $estado !== WmeDispositivo::ESTADO_ATIVO) {
            throw new WmeApiException(401, 'nao_autenticado', 'Token pessoal obrigatório.');
        }
        if ($user->isRejected()) {
            throw new WmeApiException(403, 'conta_bloqueada', 'Conta bloqueada.');
        }

        return [$user, $r->attributes->get(WmeDeviceAuthenticator::ATTR_DISPOSITIVO)];
    }

    private function run(callable $action): JsonResponse
    {
        try {
            $result = $action();
            $response = $result instanceof JsonResponse ? $result : $this->json($result);
        } catch (WmeApiException $e) {
            $response = $this->json(
                array_merge(['codigo' => $e->getCodigo(), 'message' => $e->getMessage()], $e->getExtra()),
                $e->getStatusCode()
            );
        } catch (\Throwable) {
            $response = $this->json(['codigo' => 'erro_interno', 'message' => 'Falha interna.'], 500);
        }

        return $response->setPrivate()->setMaxAge(0);
    }

    #[Route('/solicitacoes/{id}/status', name: 'status', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function status(int $id, Request $r): JsonResponse
    {
        return $this->run(function () use ($id, $r) {
            [$u] = $this->auth($r);

            $sol = $this->em->getRepository(Solicitacao::class)->find($id);
            if (!$sol instanceof Solicitacao || $sol->getSolicitanteEmail() !== $u->getEmail()) {
                throw new WmeApiException(404, 'solicitacao_nao_encontrada', 'Solicitação não encontrada.');
            }

            $comentarios = [];
            foreach ($sol->getComentarios() as $c) {
                if ($c->isInterno()) {
                    continue;
                }
                $comentarios[] = [
                    'autor' => $c->getAutorNome(),
                    'criadoEm' => $c->getCriadoEm()->format(DATE_ATOM),
                    'texto' => $c->getMensagem(),
                ];
            }

            return [
                'id' => $sol->getId(),
                'formulario' => $sol->getFormulario()?->getNome() ?? $sol->getTipoLabel(),
                'status' => $sol->getStatus(),
                'statusLabel' => $sol->getStatusLabel(),
                'criadaEm' => $sol->getCriadaEm()->format(DATE_ATOM),
                'url' => $this->generateUrl('solicitacao_detalhe', ['id' => $sol->getId()]),
                'comentarios' => $comentarios,
            ];
        });
    }

    #[Route('/solicitacoes/alteracoes', name: 'alteracoes', methods: ['GET'])]
    public function alteracoes(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            [$u] = $this->auth($r);

            $sinceParam = $r->query->get('since');
            $since = null;
            if ($sinceParam) {
                try {
                    $since = new \DateTimeImmutable($sinceParam);
                } catch (\Exception) {
                    throw new WmeApiException(400, 'since_invalido', 'Parâmetro since inválido.');
                }
            }

            $qb = $this->em->getRepository(Solicitacao::class)->createQueryBuilder('s')
                ->where('s.solicitanteEmail = :email')
                ->setParameter('email', $u->getEmail());

            if ($since instanceof \DateTimeImmutable) {
                $qb->andWhere('(s.atualizadaEm > :since OR EXISTS (
                    SELECT 1 FROM App\Entity\SolicitacaoComentario c
                    WHERE c.solicitacao = s AND c.criadoEm > :since
                ))')
                ->setParameter('since', $since);
            }

            $qb->orderBy('s.atualizadaEm', 'DESC')->setMaxResults(50);
            $rows = $qb->getQuery()->getResult();

            return [
                'servidorEm' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'alteracoes' => array_map(fn (Solicitacao $s) => [
                    'id' => $s->getId(),
                    'formulario' => $s->getFormulario()?->getNome() ?? $s->getTipoLabel(),
                    'status' => $s->getStatus(),
                    'statusLabel' => $s->getStatusLabel(),
                    'resumo' => null,
                    'atualizadoEm' => $s->getAtualizadaEm()->format(DATE_ATOM),
                    'url' => $this->generateUrl('solicitacao_detalhe', ['id' => $s->getId()]),
                ], $rows),
            ];
        });
    }
}
