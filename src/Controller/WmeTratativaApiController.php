<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Solicitacao;
use App\Entity\SolicitacaoComentario;
use App\Entity\User;
use App\Entity\WmeDispositivo;
use App\Exception\WmeApiException;
use App\Security\WmeDeviceAuthenticator;
use App\Service\SolicitacaoService;
use App\Service\TratativaAcessoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tratativa de solicitações pelo userscript do WME.
 *
 * A permissão é decidida AQUI, em toda requisição (TratativaAcessoService).
 * O que o userscript mostra ou esconde é apenas conveniência de interface.
 */
#[Route('/api/wme/tratativa', name: 'api_wme_tratativa_')]
final class WmeTratativaApiController extends AbstractController
{
    private const MAX_LISTA = 50;
    private const MAX_TEXTO = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TratativaAcessoService $acesso,
        private readonly SolicitacaoService $service,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Exige dispositivo WME ativo (o token legado de leitura do site não pode
     * alterar solicitações), conta aprovada e permissão de tratativa.
     */
    private function auth(Request $request): User
    {
        $user = $this->getUser();
        $estado = $request->attributes->get(WmeDeviceAuthenticator::ATTR_ESTADO);
        $device = $request->attributes->get(WmeDeviceAuthenticator::ATTR_DISPOSITIVO);

        if (
            !$user instanceof User
            || $estado !== WmeDispositivo::ESTADO_ATIVO
            || !$device instanceof WmeDispositivo
        ) {
            throw new WmeApiException(401, 'nao_autenticado', 'Token pessoal obrigatório.');
        }

        if ($user->isRejected()) {
            throw new WmeApiException(403, 'conta_bloqueada', 'Conta bloqueada.');
        }

        if (!$this->acesso->capacidades($user)['tratar']) {
            throw new WmeApiException(403, 'sem_permissao', 'Você não tem permissão para tratar solicitações.');
        }

        return $user;
    }

    private function run(callable $action): JsonResponse
    {
        try {
            $result = $action();
            $response = $result instanceof JsonResponse ? $result : $this->json($result);
        } catch (WmeApiException $exception) {
            $response = $this->json(array_merge([
                'codigo' => $exception->getCodigo(),
                'message' => $exception->getMessage(),
            ], $exception->getExtra()), $exception->getStatusCode());
        } catch (HttpException $exception) {
            $response = $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (\Throwable $exception) {
            $this->logger->error('WME tratativa: {class}', [
                'class' => $exception::class,
                'exception' => $exception,
            ]);

            $response = $this->json([
                'codigo' => 'erro_interno',
                'message' => 'Falha interna. Atualize a tela antes de repetir.',
            ], 500);
        }

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** @return array<string, mixed> */
    private function corpo(Request $request): array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WmeApiException(400, 'json_invalido', 'Corpo JSON inválido.');
        }

        if (!is_array($dados)) {
            throw new WmeApiException(400, 'json_invalido', 'Corpo JSON inválido.');
        }

        return $dados;
    }

    private function carregar(int $id, User $user): Solicitacao
    {
        $solicitacao = $this->em->find(Solicitacao::class, $id);

        // Mesma resposta para inexistente e sem permissão: não revela registros.
        if (!$solicitacao instanceof Solicitacao || !$this->acesso->pode($user, $solicitacao)) {
            throw new WmeApiException(404, 'solicitacao_nao_encontrada', 'Solicitação não encontrada.');
        }

        return $solicitacao;
    }

    // ------------------------------------------------------------------
    // GET /api/wme/tratativa/resumo
    // ------------------------------------------------------------------
    #[Route('/resumo', name: 'resumo', methods: ['GET'])]
    public function resumo(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $user = $this->auth($request);

            $linhas = $this->acesso->filaQueryBuilder($user)
                ->select('s.status AS status, COUNT(s.id) AS total')
                ->groupBy('s.status')
                ->getQuery()
                ->getArrayResult();

            $porStatus = array_fill_keys(array_keys(Solicitacao::STATUS_LABELS), 0);

            foreach ($linhas as $linha) {
                $porStatus[(string) $linha['status']] = (int) $linha['total'];
            }

            return [
                'porStatus' => $porStatus,
                'abertas' => array_sum(array_intersect_key(
                    $porStatus,
                    array_flip(TratativaAcessoService::STATUS_ABERTOS)
                )),
                'pendentes' => $porStatus[Solicitacao::STATUS_PENDENTE] ?? 0,
            ];
        });
    }

    // ------------------------------------------------------------------
    // GET /api/wme/tratativa/fila?status=abertas|todas|<status>&tipo=&q=&pagina=&limite=&ordem=
    // ------------------------------------------------------------------
    #[Route('/fila', name: 'fila', methods: ['GET'])]
    public function fila(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $user = $this->auth($request);

            $status = (string) $request->query->get('status', 'abertas');
            $limite = max(1, min(self::MAX_LISTA, (int) $request->query->get('limite', 25)));
            $pagina = max(1, (int) $request->query->get('pagina', 1));
            $antigas = (string) $request->query->get('ordem', 'recentes') === 'antigas';

            $qb = $this->acesso->filaQueryBuilder($user);

            if ($status === 'abertas') {
                $qb->andWhere('s.status IN (:stAbertos)')
                    ->setParameter('stAbertos', TratativaAcessoService::STATUS_ABERTOS);
            } elseif ($status !== 'todas') {
                if (!array_key_exists($status, Solicitacao::STATUS_LABELS)) {
                    throw new WmeApiException(400, 'status_invalido', 'Status inválido.');
                }

                $qb->andWhere('s.status = :stUnico')->setParameter('stUnico', $status);
            }

            $tipo = trim((string) $request->query->get('tipo', ''));

            if ($tipo !== '') {
                $qb->andWhere('s.tipo = :fTipo')->setParameter('fTipo', $tipo);
            }

            $busca = ltrim(trim((string) $request->query->get('q', '')), '#');

            if ($busca !== '' && ctype_digit($busca)) {
                $qb->andWhere('s.id = :fId')->setParameter('fId', (int) $busca);
            } elseif ($busca !== '') {
                $like = '%' . addcslashes(mb_strtolower(mb_substr($busca, 0, 80)), '%_\\') . '%';
                $qb->andWhere('(LOWER(s.solicitanteUsuario) LIKE :fTxt OR LOWER(s.solicitanteNome) LIKE :fTxt)')
                    ->setParameter('fTxt', $like);
            }

            $total = (int) (clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();

            $ordem = $antigas ? 'ASC' : 'DESC';
            $itens = $qb->orderBy('s.criadaEm', $ordem)
                ->addOrderBy('s.id', $ordem)
                ->setFirstResult(($pagina - 1) * $limite)
                ->setMaxResults($limite)
                ->getQuery()
                ->getResult();

            return [
                'itens' => array_map(fn (Solicitacao $s): array => $this->resumoItem($s), $itens),
                'total' => $total,
                'pagina' => $pagina,
                'limite' => $limite,
                'temMais' => $pagina * $limite < $total,
            ];
        });
    }

    // ------------------------------------------------------------------
    // GET /api/wme/tratativa/{id}
    // ------------------------------------------------------------------
    #[Route('/{id}', name: 'detalhe', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detalhe(int $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($id, $request) {
            $user = $this->auth($request);
            $solicitacao = $this->carregar($id, $user);

            return $this->detalheDe($solicitacao, $user);
        });
    }

    // ------------------------------------------------------------------
    // POST /api/wme/tratativa/{id}/status   {status, nota?}
    // ------------------------------------------------------------------
    #[Route('/{id}/status', name: 'status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function status(int $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($id, $request) {
            $user = $this->auth($request);
            $dados = $this->corpo($request);

            $novo = (string) ($dados['status'] ?? '');
            $nota = trim((string) ($dados['nota'] ?? ''));

            if (!array_key_exists($novo, Solicitacao::STATUS_LABELS)) {
                throw new WmeApiException(400, 'status_invalido', 'Status inválido.');
            }

            if (mb_strlen($nota) > self::MAX_TEXTO) {
                throw new WmeApiException(400, 'nota_longa', 'A nota excede ' . self::MAX_TEXTO . ' caracteres.');
            }

            $solicitacao = $this->carregar($id, $user);
            $bloqueio = $this->acesso->bloqueioDeStatus($user, $solicitacao);

            if ($bloqueio !== null) {
                throw new WmeApiException(403, 'somente_champ', $bloqueio);
            }

            $anterior = $solicitacao->getStatus();

            $this->service->mudarStatus($solicitacao, $novo, $user, $nota !== '' ? $nota : null);

            $this->logger->info('WME tratativa: status alterado', [
                'solicitacaoId' => $id,
                'usuarioId' => $user->getId(),
                'de' => $anterior,
                'para' => $novo,
            ]);

            return [
                'ok' => true,
                'alterado' => $anterior !== $novo,
                'status' => $solicitacao->getStatus(),
                'statusLabel' => $solicitacao->getStatusLabel(),
            ];
        });
    }

    // ------------------------------------------------------------------
    // POST /api/wme/tratativa/{id}/comentarios   {mensagem, interno?}
    // ------------------------------------------------------------------
    #[Route('/{id}/comentarios', name: 'comentar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function comentar(int $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($id, $request) {
            $user = $this->auth($request);
            $dados = $this->corpo($request);

            $mensagem = trim((string) ($dados['mensagem'] ?? ''));
            $interno = filter_var($dados['interno'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($mensagem === '') {
                throw new WmeApiException(400, 'mensagem_vazia', 'Escreva uma mensagem.');
            }

            if (mb_strlen($mensagem) > self::MAX_TEXTO) {
                throw new WmeApiException(400, 'mensagem_longa', 'A mensagem excede ' . self::MAX_TEXTO . ' caracteres.');
            }

            $solicitacao = $this->carregar($id, $user);
            $comentario = $this->service->adicionarComentario($solicitacao, $mensagem, $user, null, $interno);

            $this->logger->info('WME tratativa: comentário', [
                'solicitacaoId' => $id,
                'usuarioId' => $user->getId(),
                'interno' => $interno,
            ]);

            return $this->json($this->comentarioItem($comentario), 201);
        });
    }

    // ------------------------------------------------------------------
    // Serialização
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function resumoItem(Solicitacao $s): array
    {
        return [
            'id' => $s->getId(),
            'tipo' => $s->getTipo(),
            'tipoLabel' => $s->getTipoLabel(),
            'formulario' => $s->getFormulario()?->getNome() ?? $s->getTipoLabel(),
            'status' => $s->getStatus(),
            'statusLabel' => $s->getStatusLabel(),
            'solicitante' => [
                'nome' => $s->getSolicitanteNome(),
                'usuario' => $s->getSolicitanteUsuario(),
            ],
            'criadaEm' => $s->getCriadaEm()->format(DATE_ATOM),
            'atualizadaEm' => $s->getAtualizadaEm()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function comentarioItem(SolicitacaoComentario $c): array
    {
        return [
            'id' => $c->getId(),
            'autor' => $c->getAutorNome(),
            'texto' => $c->getMensagem(),
            'interno' => $c->isInterno(),
            'criadoEm' => $c->getCriadoEm()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function detalheDe(Solicitacao $s, User $user): array
    {
        $wme = $s->getDados()['_wme'] ?? [];

        $comentarios = $s->getComentarios()->toArray();
        usort($comentarios, static fn (SolicitacaoComentario $a, SolicitacaoComentario $b): int
            => $a->getCriadoEm() <=> $b->getCriadoEm());

        $historico = [];

        foreach ($s->getHistoricos() as $h) {
            $historico[] = [
                'de' => $h->getStatusAnteriorLabel(),
                'para' => $h->getStatusNovoLabel(),
                'autor' => $h->getAutor()?->getName(),
                'nota' => $h->getNota(),
                'criadoEm' => $h->getCriadoEm()->format(DATE_ATOM),
            ];
        }

        usort($historico, static fn (array $a, array $b): int => strcmp($a['criadoEm'], $b['criadoEm']));

        $anexos = [];

        foreach ((is_array($wme['anexos'] ?? null) ? $wme['anexos'] : []) as $anexo) {
            if (!is_array($anexo) || !isset($anexo['nome'])) {
                continue;
            }

            $anexos[] = [
                'nome' => (string) $anexo['nome'],
                'mime' => (string) ($anexo['mime'] ?? ''),
                'bytes' => (int) ($anexo['bytes'] ?? 0),
                'campo' => (string) ($anexo['campo'] ?? ''),
            ];
        }

        $bloqueio = $this->acesso->bloqueioDeStatus($user, $s);

        $statusPermitidos = [];

        if ($bloqueio === null) {
            foreach (Solicitacao::STATUS_LABELS as $valor => $label) {
                if ($valor !== $s->getStatus()) {
                    $statusPermitidos[] = ['value' => $valor, 'label' => $label];
                }
            }
        }

        return $this->resumoItem($s) + [
            'respostas' => $this->respostas($s),
            'contexto' => is_array($wme['contexto'] ?? null) ? $wme['contexto'] : null,
            'anexos' => $anexos,
            'comentarios' => array_map(fn (SolicitacaoComentario $c): array => $this->comentarioItem($c), $comentarios),
            'historico' => $historico,
            'responsaveis' => array_values(array_map(
                static fn (User $r): string => $r->getName() ?: (string) $r->getWazeNickname(),
                $s->getResponsaveis()->toArray()
            )),
            'acoes' => [
                'statusPermitidos' => $statusPermitidos,
                'bloqueio' => $bloqueio,
                'podeComentar' => true,
                'podeInterno' => true,
            ],
            'url' => '/solicitacoes/' . $s->getId(),
        ];
    }

    /** @return list<array{chave: string, label: string, tipo: string, valor: string}> */
    private function respostas(Solicitacao $s): array
    {
        $campos = [];

        foreach (($s->getFormulario()?->getCampos() ?? []) as $campo) {
            $campos[$campo->getChave()] = $campo;
        }

        $saida = [];

        foreach (($s->getDadosDinamicos() ?? []) as $chave => $valor) {
            $campo = $campos[$chave] ?? null;
            $tipo = $campo?->getTipo() ?? 'text';

            if (in_array($tipo, ['file', 'image'], true)) {
                continue; // anexos têm lista própria
            }

            $saida[] = [
                'chave' => (string) $chave,
                'label' => $campo?->getLabel() ?? (string) $chave,
                'tipo' => $tipo,
                'valor' => $this->valorEmTexto($valor, $campo?->getOpcoes()),
            ];
        }

        return $saida;
    }

    /** @param array<string, mixed>|null $config */
    private function valorEmTexto(mixed $valor, ?array $config): string
    {
        if (is_bool($valor)) {
            return $valor ? 'Sim' : 'Não';
        }

        if (is_array($valor)) {
            return implode(', ', array_map(fn (mixed $v): string => $this->valorEmTexto($v, $config), $valor));
        }

        if (!is_scalar($valor)) {
            return '';
        }

        $texto = (string) $valor;

        foreach (($config['opcoes'] ?? []) as $chave => $rotulo) {
            if (is_array($rotulo)) {
                if ((string) ($rotulo['value'] ?? '') === $texto && isset($rotulo['label'])) {
                    return (string) $rotulo['label'];
                }
            } elseif (is_scalar($rotulo) && (string) $chave === $texto) {
                return (string) $rotulo;
            }
        }

        return $texto;
    }
}
