<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EscolaInep;
use App\Entity\User;
use App\Service\EscolaReservaService;
use App\Service\WazeSnapshotNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EscolaApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EscolaReservaService $reservas,
    ) {
    }

    #[Route(
        '/api/escolas/uf/{uf}/reservar',
        name: 'api_escolas_reservar',
        methods: ['POST']
    )]
    public function reservar(Request $request, string $uf): JsonResponse
    {
        $user = $this->requireApiUser();
        $uf = strtoupper(trim($uf));

        if ($erro = $this->validarAcessoUf($user, $uf)) {
            return $erro;
        }

        $data = $this->lerJson($request);

        if ($data === null) {
            return $this->json(
                ['error' => 'Corpo JSON inválido.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $username = trim((string) ($data['username'] ?? ''));

        if ($erro = $this->validarUsername($username, true)) {
            return $erro;
        }

        $limite = (int) (
            $data['limite'] ?? EscolaReservaService::LIMITE_MAXIMO
        );

        $reserva = $this->reservas->reservar(
            $uf,
            $username,
            $limite
        );

        $escolas = $reserva['escolas'];

        if ($escolas === []) {
            return $this->json([
                'username' => $username,
                'uf' => $uf,
                'total' => 0,
                'escolas' => [],
                'ttl_minutos' => EscolaReservaService::TTL_MINUTOS,
                'message' =>
                    'Nenhuma escola disponível para reserva nesta UF.',
            ]);
        }

        return $this->json([
            'username' => $username,
            'uf' => $uf,
            'total' => count($escolas),
            'retomadas' => $reserva['retomadas'],
            'novas' => $reserva['novas'],
            'expiradas_reaproveitadas' =>
                $reserva['expiradas_reaproveitadas'],
            'ttl_minutos' => EscolaReservaService::TTL_MINUTOS,
            'expira_em' => $reserva['expira_em']->format(DATE_ATOM),
            'escolas' => array_map(
                $this->serializeEscola(...),
                $escolas
            ),
        ]);
    }

    #[Route(
        '/api/escolas/uf/{uf}/renovar',
        name: 'api_escolas_renovar',
        methods: ['POST']
    )]
    public function renovar(Request $request, string $uf): JsonResponse
    {
        $user = $this->requireApiUser();
        $uf = strtoupper(trim($uf));

        if ($erro = $this->validarAcessoUf($user, $uf)) {
            return $erro;
        }

        $data = $this->lerJson($request);

        if ($data === null) {
            return $this->json(
                ['error' => 'Corpo JSON inválido.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $username = trim((string) ($data['username'] ?? ''));

        if ($erro = $this->validarUsername($username)) {
            return $erro;
        }

        $renovadas = $this->reservas->renovar($uf, $username);

        return $this->json([
            'username' => $username,
            'uf' => $uf,
            'renovadas' => $renovadas,
            'ttl_minutos' => EscolaReservaService::TTL_MINUTOS,
            'expira_em' => (new \DateTimeImmutable())
                ->modify(sprintf(
                    '+%d minutes',
                    EscolaReservaService::TTL_MINUTOS
                ))
                ->format(DATE_ATOM),
            'message' => $renovadas > 0
                ? sprintf('%d reserva(s) renovada(s).', $renovadas)
                : 'Nenhuma escola reservada encontrada '
                    . 'para este username nesta UF.',
        ]);
    }

    #[Route(
        '/api/escolas/uf/{uf}/liberar',
        name: 'api_escolas_liberar',
        methods: ['POST']
    )]
    public function liberar(Request $request, string $uf): JsonResponse
    {
        $user = $this->requireApiUser();
        $uf = strtoupper(trim($uf));

        if ($erro = $this->validarAcessoUf($user, $uf)) {
            return $erro;
        }

        $data = $this->lerJson($request);

        if ($data === null) {
            return $this->json(
                ['error' => 'Corpo JSON inválido.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $username = trim((string) ($data['username'] ?? ''));

        if ($erro = $this->validarUsername($username)) {
            return $erro;
        }

        $liberadas = $this->reservas->liberar($uf, $username);

        return $this->json([
            'username' => $username,
            'uf' => $uf,
            'liberadas' => $liberadas,
            'message' => $liberadas > 0
                ? sprintf(
                    '%d escola(s) liberada(s) com sucesso.',
                    $liberadas
                )
                : 'Nenhuma escola reservada encontrada '
                    . 'para este username nesta UF.',
        ]);
    }

    #[Route(
        '/api/escolas/{id}/status',
        name: 'api_escola_status',
        requirements: ['id' => '\d+'],
        methods: ['PATCH', 'POST']
    )]
    public function atualizarStatus(
        Request $request,
        int $id,
    ): JsonResponse {
        $user = $this->requireApiUser();

        if (!$user->hasPermission(User::PERMISSION_ESCOLAS)) {
            return $this->json(
                [
                    'error' =>
                        'Você não tem permissão para atualizar escolas.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }

        $escola = $this->em->find(EscolaInep::class, $id);

        if (!$escola instanceof EscolaInep) {
            return $this->json(
                ['error' => 'Escola não encontrada.'],
                Response::HTTP_NOT_FOUND
            );
        }

        if (!$user->canAccessUf((string) $escola->getUf())) {
            return $this->json(
                ['error' => 'Você não tem acesso a esta UF.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $data = $this->lerJson($request);

        if ($data === null) {
            return $this->json(
                ['error' => 'Corpo JSON inválido.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $status = $data['status'] ?? null;
        $username = trim((string) ($data['username'] ?? ''));

        $statusPermitidos = [
            EscolaInep::STATUS_CONFIRMADO,
            EscolaInep::STATUS_NAO_LOCALIZADO,
            EscolaInep::STATUS_PULADO,
        ];

        if (
            !is_string($status)
            || !in_array($status, $statusPermitidos, true)
        ) {
            return $this->json(
                [
                    'error' => 'Status inválido. Use confirmado, '
                        . 'nao_localizado ou pulado.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($erro = $this->validarUsername($username)) {
            return $erro;
        }

        if (
            $escola->getReservadoPara() !== null
            && $escola->getReservadoPara() !== $username
        ) {
            return $this->json(
                [
                    'error' =>
                        'Esta escola está reservada para outro usuário.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }

        // Valida o snapshot do Place antes de alterar qualquer coisa.
        $snapshot = null;

        if (
            $status === EscolaInep::STATUS_CONFIRMADO
            && array_key_exists('waze', $data)
        ) {
            try {
                $snapshot = WazeSnapshotNormalizer::normalizar(
                    $data['waze']
                );
            } catch (\InvalidArgumentException $e) {
                return $this->json(
                    ['error' => $e->getMessage()],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $agora = new \DateTimeImmutable();

        $escola->setStatus($status);
        $escola->setUpdatedAt($agora);

        if ($status === EscolaInep::STATUS_PULADO) {
            $escola->setReservadoPara(null);
            $escola->setReservadoEm(null);
        } else {
            $escola->setReservadoPara($username);
            $escola->setReservadoEm(null);
        }

        if ($snapshot !== null) {
            $escola->setWazeSnapshot($snapshot);
            $escola->setWazeSnapshotEm($agora);
        }

        $this->em->flush();

        return $this->json([
            'message' => 'Status atualizado com sucesso.',
            // Só é true depois do flush: o snapshot realmente foi gravado.
            'waze_persistido' => $snapshot !== null,
            'escola' => $this->serializeEscola($escola),
        ]);
    }

    #[Route(
        '/api/escolas/uf/{uf}/proxima',
        name: 'api_escola_proxima',
        methods: ['GET']
    )]
    public function proximaPorUf(
        Request $request,
        string $uf,
    ): JsonResponse {
        $user = $this->requireApiUser();
        $uf = strtoupper(trim($uf));

        if ($erro = $this->validarAcessoUf($user, $uf)) {
            return $erro;
        }

        $somenteAtualizadas = $request->query->getBoolean(
            'atualizada',
            false
        );

        $qb = $this->em->createQueryBuilder();

        $qb->select('e')
            ->from(EscolaInep::class, 'e')
            ->where('e.uf = :uf')
            ->setParameter('uf', $uf);

        if ($somenteAtualizadas) {
            $qb->andWhere('e.updatedAt IS NOT NULL')
                ->orderBy('e.updatedAt', 'DESC');
        } else {
            $qb->andWhere('e.status IS NULL')
                ->orderBy('e.updatedAt', 'ASC')
                ->addOrderBy('e.escola', 'ASC');
        }

        $escola = $qb->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$escola instanceof EscolaInep) {
            return $this->json([
                'escola' => null,
                'message' => $somenteAtualizadas
                    ? 'Nenhuma escola atualizada encontrada para esta UF.'
                    : 'Nenhuma escola pendente encontrada para esta UF.',
            ]);
        }

        return $this->json([
            'escola' => $this->serializeEscola($escola),
        ]);
    }

    #[Route(
        '/api/escolas/uf/{uf}',
        name: 'api_escolas_por_uf',
        methods: ['GET']
    )]
    public function porUf(string $uf): JsonResponse
    {
        $user = $this->requireApiUser();
        $uf = strtoupper(trim($uf));

        if ($erro = $this->validarAcessoUf($user, $uf)) {
            return $erro;
        }

        $escolas = $this->em
            ->getRepository(EscolaInep::class)
            ->findBy(
                ['uf' => $uf],
                ['escola' => 'ASC']
            );

        return $this->json([
            'uf' => $uf,
            'total' => count($escolas),
            'escolas' => array_map(
                $this->serializeEscola(...),
                $escolas
            ),
        ]);
    }

    #[Route(
        '/api/escolas/{id}',
        name: 'api_escola_update',
        requirements: ['id' => '\d+'],
        methods: ['PATCH', 'POST']
    )]
    public function update(
        Request $request,
        int $id,
    ): JsonResponse {
        $user = $this->requireApiUser();

        if (!$user->hasPermission(User::PERMISSION_ESCOLAS)) {
            return $this->json(
                [
                    'error' =>
                        'Você não tem permissão para atualizar escolas.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }

        $escola = $this->em->find(EscolaInep::class, $id);

        if (!$escola instanceof EscolaInep) {
            return $this->json(
                ['error' => 'Escola não encontrada.'],
                Response::HTTP_NOT_FOUND
            );
        }

        if (!$user->canAccessUf((string) $escola->getUf())) {
            return $this->json(
                ['error' => 'Você não tem acesso a esta UF.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $data = $this->lerJson($request);

        if ($data === null) {
            return $this->json(
                ['error' => 'Corpo JSON inválido.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $alterou = false;

        if (array_key_exists('link_waze', $data)) {
            $linkWaze = $this->normalizeNullableString(
                $data['link_waze']
            );

            if (
                $linkWaze !== null
                && !$this->isWazeEditorLink($linkWaze)
            ) {
                return $this->json(
                    [
                        'error' => 'O link_waze deve ser uma URL '
                            . 'válida do Waze Map Editor.',
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $escola->setLinkWaze($linkWaze);
            $alterou = true;
        }

        if (array_key_exists('link_area_escolar', $data)) {
            $linkArea = $this->normalizeNullableString(
                $data['link_area_escolar']
            );

            if (
                $linkArea !== null
                && !$this->isWazeLink($linkArea)
            ) {
                return $this->json(
                    [
                        'error' => 'O link_area_escolar deve ser '
                            . 'uma URL válida do Waze.',
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $escola->setLinkAreaEscolar($linkArea);
            $alterou = true;
        }

        if (!$alterou) {
            return $this->json(
                [
                    'error' => 'Informe ao menos um campo para atualizar: '
                        . 'link_waze ou link_area_escolar.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        $escola->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json([
            'message' => 'Escola atualizada com sucesso.',
            'escola' => $this->serializeEscola($escola),
        ]);
    }

    private function requireApiUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(
                'Autenticação por token necessária.'
            );
        }

        return $user;
    }

    private function validarAcessoUf(
        User $user,
        string $uf,
    ): ?JsonResponse {
        if (!in_array($uf, User::ALL_UFS, true)) {
            return $this->json(
                ['error' => 'UF inválida.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$user->canAccessUf($uf)) {
            return $this->json(
                ['error' => 'Você não tem acesso a esta UF.'],
                Response::HTTP_FORBIDDEN
            );
        }

        return null;
    }

    private function validarUsername(
        string $username,
        bool $limitarTamanho = false,
    ): ?JsonResponse {
        if ($username === '') {
            return $this->json(
                ['error' => 'O campo username é obrigatório.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($limitarTamanho && mb_strlen($username) > 100) {
            return $this->json(
                [
                    'error' =>
                        'O username deve ter no máximo 100 caracteres.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lerJson(Request $request): ?array
    {
        $data = json_decode(
            $request->getContent(),
            true
        );

        return is_array($data) ? $data : null;
    }

    /**
     * Reproduz a classificação exibida no template da escola.
     *
     * Não usa o status da revisão e não presume que uma escola
     * esteja ativa quando o texto não permite classificá-la.
     */
    private function situacaoFuncionamento(
        EscolaInep $escola,
    ): ?string {
        $restricao = mb_strtoupper(
            trim((string) $escola->getRestricaoAtendimento()),
            'UTF-8'
        );

        if (str_contains($restricao, 'PARALISADA')) {
            return 'Paralisada';
        }

        if (
            str_contains($restricao, 'SEM RESTRIÇÃO')
            || str_contains($restricao, 'SEM RESTRICAO')
        ) {
            return 'Ativa';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEscola(EscolaInep $escola): array
    {
        return [
            'id' => $escola->getId(),
            'codigo_inep' => $escola->getCodigoInep(),
            'escola' => $escola->getEscola(),
            'uf' => $escola->getUf(),
            'municipio' => $escola->getMunicipio(),

            'localizacao' => $escola->getLocalizacao(),
            'localidade_diferenciada' =>
                $escola->getLocalidadeDiferenciada(),

            'categoria_administrativa' =>
                $escola->getCategoriaAdministrativa(),
            'dependencia_administrativa' =>
                $escola->getDependenciaAdministrativa(),
            'categoria_escola_privada' =>
                $escola->getCategoriaEscolaPrivada(),

            'conveniada' => $escola->getConveniada(),
            'regulamentacao' => $escola->getRegulamentacao(),
            'porte' => $escola->getPorte(),
            'etapas_ensino' => $escola->getEtapasEnsino(),
            'outras_ofertas' => $escola->getOutrasOfertas(),

            'endereco' => $escola->getEndereco(),
            'telefone' => $escola->getTelefone(),

            'latitude' => $escola->getLatitude(),
            'longitude' => $escola->getLongitude(),

            'restricao_atendimento' =>
                $escola->getRestricaoAtendimento(),

            'situacao_funcionamento' =>
                $this->situacaoFuncionamento($escola),

            'situacao_funcionamento_origem' =>
                'restricao_atendimento',

            'link_waze' => $escola->getLinkWaze(),
            'permanent_hazard_id' =>
                $escola->getPermanentHazardId(),
            'link_area_escolar' =>
                $escola->getLinkAreaEscolar(),

            // Status da revisão, separado do funcionamento.
            'status' => $escola->getStatus(),

            'reservado_para' => $escola->getReservadoPara(),
            'reservado_em' =>
                $escola->getReservadoEm()?->format(DATE_ATOM),

            'imported_at' =>
                $escola->getImportedAt()->format(DATE_ATOM),
            'updated_at' =>
                $escola->getUpdatedAt()?->format(DATE_ATOM),

            'waze_snapshot_em' =>
                $escola->getWazeSnapshotEm()?->format(DATE_ATOM),
        ];
    }

    private function normalizeNullableString(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isWazeLink(string $url): bool
    {
        return $this->isValidUrl($url)
            && (bool) preg_match(
                '/^https?:\/\/([a-z0-9-]+\.)?waze\.com\//i',
                $url
            );
    }

    private function isWazeEditorLink(string $url): bool
    {
        return $this->isWazeLink($url)
            && str_contains($url, '/editor');
    }

    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}