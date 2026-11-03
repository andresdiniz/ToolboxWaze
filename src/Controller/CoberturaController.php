<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\AccessControlTrait;
use App\Entity\User;
use App\Service\CoberturaVerificacaoService;
use App\Service\PostoStatsService;
use App\Service\RadarStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/cobertura', name: 'cobertura_')]
#[IsGranted('ROLE_USER')]
final class CoberturaController extends AbstractController
{
    public function __construct(
        private readonly RadarStatsService $radarStats,
        private readonly PostoStatsService $postoStats,
        private readonly CoberturaVerificacaoService $verificacoes,
    ) {
    }

    /**
     * Mantém a página atual de cobertura de radares.
     */
    #[Route('/radares', name: 'radar', methods: ['GET'])]
    public function radar(): Response
    {
        $this->requirePermission(User::PERMISSION_RADARES);

        $user = $this->usuarioAtual();
        $allowedUfs = $user->getUfsForQuery();

        $porUf = $this->radarStats
            ->getCoberturaWazePorUf($allowedUfs);

        return $this->render('cobertura/radar.html.twig', [
            ...$this->dadosGrafico($porUf),

            'porUf' => $porUf,

            'porUfAll' => $this->radarStats
                ->getPorUf($allowedUfs),

            'semWaze' => $this->radarStats
                ->getSemWazePrioritarios($allowedUfs, 50),

            'kpis' => $this->radarStats
                ->getKpis($allowedUfs),

            'allowedUfs' => $allowedUfs,
        ]);
    }

    /**
     * Mantém a página atual de cobertura de postos.
     */
    #[Route('/postos', name: 'posto', methods: ['GET'])]
    public function posto(): Response
    {
        $this->requirePermission(User::PERMISSION_POSTOS);

        $user = $this->usuarioAtual();
        $allowedUfs = $user->getUfsForQuery();

        $porUf = $this->postoStats
            ->getCoberturaPorUf($allowedUfs);

        return $this->render('cobertura/posto.html.twig', [
            ...$this->dadosGrafico($porUf),

            'porUf' => $porUf,

            'kpis' => $this->postoStats
                ->getKpis($allowedUfs),

            'allowedUfs' => $allowedUfs,
        ]);
    }

    /**
     * Lista os trechos visíveis para o usuário.
     *
     * Exemplo:
     * GET /cobertura/trechos/dados?uf=MG&pagina=1
     */
    #[Route(
        '/trechos/dados',
        name: 'trechos_dados',
        methods: ['GET']
    )]
    public function trechos(Request $request): JsonResponse
    {
        $this->requirePermission(User::PERMISSION_RADARES);

        $user = $this->usuarioAtual();
        $uf = $this->ufPermitida($request, $user);
        $pagina = $this->pagina($request);

        $resultado = $this->verificacoes->listarTrechos(
            usuario: $user,
            uf: $uf,
            pagina: $pagina,
            porPagina: 25,
        );

        return $this->respostaPrivada([
            'sucesso' => true,
            'dados' => $resultado,
        ]);
    }

    /**
     * Lista os radares de um trecho.
     *
     * O serviço deve verificar a permissão sobre o trecho
     * antes de retornar qualquer informação.
     */
    #[Route(
        '/trechos/{id}/radares',
        name: 'trecho_radares',
        requirements: ['id' => '[1-9]\d*'],
        methods: ['GET']
    )]
    public function radaresDoTrecho(
        int $id,
        Request $request,
    ): JsonResponse {
        $this->requirePermission(User::PERMISSION_RADARES);

        $resultado = $this->verificacoes->listarRadaresDoTrecho(
            trechoId: $id,
            usuario: $this->usuarioAtual(),
            pagina: $this->pagina($request),
            porPagina: 25,
        );

        return $this->respostaPrivada([
            'sucesso' => true,
            'dados' => $resultado,
        ]);
    }

    /**
     * Consulta o vínculo do radar no provedor externo.
     *
     * Não confirma automaticamente o radar e não gera pontos.
     *
     * Usa POST porque pode registrar uma tentativa de consulta
     * e consumir recursos de um serviço externo.
     */
    #[Route(
        '/radares/{id}/consultar',
        name: 'radar_consultar',
        requirements: ['id' => '[1-9]\d*'],
        methods: ['POST']
    )]
    public function consultarRadar(
        int $id,
        Request $request,
    ): JsonResponse {
        $this->requirePermission(User::PERMISSION_RADARES);

        $this->validarCsrf(
            request: $request,
            tokenId: 'cobertura_consultar_' . $id,
        );

        $resultado = $this->verificacoes->consultarRadar(
            radarId: $id,
            usuario: $this->usuarioAtual(),
        );

        return $this->respostaPrivada([
            'sucesso' => true,
            'dados' => $resultado,
        ]);
    }

    /**
     * Registra a decisão de um editor autenticado.
     *
     * Corpo JSON:
     * {
     *   "resultado": "confirmado",
     *   "observacao": "Conferido no WME."
     * }
     *
     * O identificador do editor vem da sessão, nunca do JSON.
     */
    #[Route(
        '/radares/{id}/confirmar',
        name: 'radar_confirmar',
        requirements: ['id' => '[1-9]\d*'],
        methods: ['POST']
    )]
    public function confirmarRadar(
        int $id,
        Request $request,
    ): JsonResponse {
        $this->requirePermission(User::PERMISSION_RADARES);

        $this->validarCsrf(
            request: $request,
            tokenId: 'cobertura_confirmar_' . $id,
        );

        $dados = $request->toArray();

        $resultado = $dados['resultado'] ?? null;

        if (
            !is_string($resultado)
            || !in_array(
                $resultado,
                ['confirmado', 'divergente', 'inconclusivo'],
                true
            )
        ) {
            throw new BadRequestHttpException(
                'Resultado inválido. Use confirmado, '
                . 'divergente ou inconclusivo.'
            );
        }

        $observacao = $dados['observacao'] ?? '';

        if (!is_string($observacao)) {
            throw new BadRequestHttpException(
                'A observação deve ser um texto.'
            );
        }

        $observacao = trim($observacao);

        if (mb_strlen($observacao) > 2000) {
            throw new BadRequestHttpException(
                'A observação deve ter no máximo 2000 caracteres.'
            );
        }

        if ($resultado === 'divergente' && $observacao === '') {
            throw new BadRequestHttpException(
                'Descreva a divergência encontrada.'
            );
        }

        $registro = $this->verificacoes->registrarVerificacao(
            radarId: $id,
            usuario: $this->usuarioAtual(),
            resultado: $resultado,
            observacao: $observacao,
        );

        return $this->respostaPrivada([
            'sucesso' => true,
            'dados' => $registro,
        ]);
    }

    /**
     * Ranking de primeiras confirmações válidas.
     *
     * Sem datas: mês atual no fuso configurado na aplicação.
     *
     * Exemplo:
     * GET /cobertura/ranking/dados
     *     ?inicio=2026-11-01&fim=2026-11-30&uf=MG
     */
    #[Route(
        '/ranking/dados',
        name: 'ranking_dados',
        methods: ['GET']
    )]
    public function ranking(Request $request): JsonResponse
    {
        $this->requirePermission(User::PERMISSION_RADARES);

        $user = $this->usuarioAtual();
        $uf = $this->ufPermitida($request, $user);

        $hoje = new \DateTimeImmutable('today');

        $inicioTexto = $request->query->get(
            'inicio',
            $hoje->format('Y-m-01')
        );

        $fimTexto = $request->query->get(
            'fim',
            $hoje->format('Y-m-d')
        );

        $inicio = $this->dataValida($inicioTexto, 'inicio');
        $fim = $this->dataValida($fimTexto, 'fim');

        if ($fim < $inicio) {
            throw new BadRequestHttpException(
                'A data final deve ser igual ou posterior à inicial.'
            );
        }

        // Limita consultas excessivamente amplas.
        if ((int) $inicio->diff($fim)->days > 366) {
            throw new BadRequestHttpException(
                'O período máximo permitido é de 366 dias.'
            );
        }

        $resultado = $this->verificacoes->ranking(
            usuario: $user,
            uf: $uf,
            inicio: $inicio,
            fimExclusivo: $fim->modify('+1 day'),
            pagina: $this->pagina($request),
            porPagina: 25,
        );

        return $this->respostaPrivada([
            'sucesso' => true,
            'periodo' => [
                'inicio' => $inicio->format('Y-m-d'),
                'fim' => $fim->format('Y-m-d'),
            ],
            'uf' => $uf,
            'dados' => $resultado,
        ]);
    }

    private function usuarioAtual(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(
                'É necessário estar autenticado.'
            );
        }

        return $user;
    }

    private function ufPermitida(
        Request $request,
        User $user,
    ): ?string {
        $valor = $request->query->get('uf');

        if ($valor === null || $valor === '') {
            return null;
        }

        if (!is_string($valor)) {
            throw new BadRequestHttpException('UF inválida.');
        }

        $uf = strtoupper(trim($valor));

        if (!in_array($uf, User::ALL_UFS, true)) {
            throw new BadRequestHttpException('UF inválida.');
        }

        if (!$user->canAccessUf($uf)) {
            throw $this->createAccessDeniedException(
                'Você não possui acesso a esta UF.'
            );
        }

        return $uf;
    }

    private function pagina(Request $request): int
    {
        $pagina = $request->query->getInt('pagina', 1);

        if ($pagina < 1 || $pagina > 10000) {
            throw new BadRequestHttpException(
                'A página deve estar entre 1 e 10000.'
            );
        }

        return $pagina;
    }

    private function validarCsrf(
        Request $request,
        string $tokenId,
    ): void {
        $token = $request->headers->get('X-CSRF-TOKEN');

        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException(
                'Token de segurança inválido. Recarregue a página.'
            );
        }
    }

    private function dataValida(
        mixed $valor,
        string $campo,
    ): \DateTimeImmutable {
        if (!is_string($valor)) {
            throw new BadRequestHttpException(
                sprintf('O campo %s deve ser uma data.', $campo)
            );
        }

        $data = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $valor
        );

        if (!$data || $data->format('Y-m-d') !== $valor) {
            throw new BadRequestHttpException(
                sprintf(
                    'O campo %s deve usar o formato AAAA-MM-DD.',
                    $campo
                )
            );
        }

        return $data;
    }

    private function respostaPrivada(array $dados): JsonResponse
    {
        $response = $this->json($dados);

        // Não compartilhar respostas de usuários com UFs distintas.
        $response->headers->set(
            'Cache-Control',
            'private, no-store'
        );

        return $response;
    }

    private function dadosGrafico(array $porUf): array
    {
        return [
            'criticas' => array_values(array_filter(
                $porUf,
                static fn (array $r): bool =>
                    (float) $r['pct'] < 25
            )),

            'parciais' => array_values(array_filter(
                $porUf,
                static fn (array $r): bool =>
                    (float) $r['pct'] >= 25
                    && (float) $r['pct'] < 75
            )),

            'boas' => array_values(array_filter(
                $porUf,
                static fn (array $r): bool =>
                    (float) $r['pct'] >= 75
            )),

            'chartLabels' => array_column($porUf, 'uf'),

            'chartComWaze' => array_map(
                'intval',
                array_column($porUf, 'com_waze')
            ),

            'chartSemWaze' => array_map(
                'intval',
                array_column($porUf, 'sem_waze')
            ),

            'chartPct' => array_map(
                'floatval',
                array_column($porUf, 'pct')
            ),
        ];
    }
}