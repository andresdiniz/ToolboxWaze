<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EstabelecimentoSaude;
use App\Entity\User;
use App\Service\SaudeReservaService;
use App\Service\WazeSnapshotNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API de estabelecimentos de saúde (CNES). Mesmo padrão da API de escolas.
 * Autenticação por token (header X-API-TOKEN), permissão PERM_SAUDE e acesso por UF.
 */
final class SaudeApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SaudeReservaService $reservas,
    ) {
    }

    #[Route('/api/saude/uf/{uf}', name: 'api_saude_por_uf', methods: ['GET'])]
    public function porUf(Request $request, string $uf): JsonResponse
    {
        $user = $this->requireApiUser();
        $uf   = strtoupper(trim($uf));

        if ($erro = $this->validarAcesso($user, $uf)) {
            return $erro;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(EstabelecimentoSaude::class, 'e')
            ->where('e.uf = :uf')
            ->setParameter('uf', $uf)
            ->orderBy('e.nomeFantasia', 'ASC');

        $municipio = trim((string) $request->query->get('municipio', ''));

        if ($municipio !== '') {
            $qb->andWhere('e.municipio = :municipio')->setParameter('municipio', $municipio);
        }

        if ($request->query->has('ativo')) {
            $qb->andWhere('e.ativo = :ativo')->setParameter('ativo', $request->query->getBoolean('ativo'));
        }

        /** @var list<EstabelecimentoSaude> $itens */
        $itens = $qb->getQuery()->getResult();

        return $this->json([
            'uf'       => $uf,
            'total'    => count($itens),
            'unidades' => array_map($this->serialize(...), $itens),
        ]);
    }

    #[Route('/api/saude/uf/{uf}/proxima', name: 'api_saude_proxima', methods: ['GET'])]
    public function proximaPorUf(Request $request, string $uf): JsonResponse
    {
        $user = $this->requireApiUser();
        $uf   = strtoupper(trim($uf));

        if ($erro = $this->validarAcesso($user, $uf)) {
            return $erro;
        }

        $somenteAtualizadas = $request->query->getBoolean('atualizada', false);

        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(EstabelecimentoSaude::class, 'e')
            ->where('e.uf = :uf')
            ->setParameter('uf', $uf);

        if ($somenteAtualizadas) {
            $qb->andWhere('e.status IS NOT NULL')
                ->orderBy('e.updatedAt', 'DESC');
        } else {
            $qb->andWhere('e.ativo = true')
                ->andWhere('e.status IS NULL')
                ->orderBy('e.nomeFantasia', 'ASC');
        }

        $item = $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();

        if (!$item instanceof EstabelecimentoSaude) {
            return $this->json([
                'unidade' => null,
                'message' => $somenteAtualizadas
                    ? 'Nenhum estabelecimento atualizado encontrado para esta UF.'
                    : 'Nenhum estabelecimento pendente encontrado para esta UF.',
            ]);
        }

        return $this->json(['unidade' => $this->serialize($item)]);
    }

    #[Route('/api/saude/uf/{uf}/reservar', name: 'api_saude_reservar', methods: ['POST'])]
    public function reservar(Request $request, string $uf): JsonResponse
    {
        $user = $this->requireApiUser();
        $uf   = strtoupper(trim($uf));

        if ($erro = $this->validarAcesso($user, $uf, true)) {
            return $erro;
        }

        $data = $this->lerJson($request);

        if ($data === null) {
            return $this->json(['error' => 'Corpo JSON inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $username = trim((string) ($data['username'] ?? ''));

        if ($erro = $this->validarUsername($username, true)) {
            return $erro;
        }

        $limite  = (int) ($data['limite'] ?? SaudeReservaService::LIMITE_MAXIMO);
        $reserva = $this->reservas->reservar($uf, $username, $limite);
        $itens   = $reserva['itens'];

        if ($itens === []) {
            return $this->json([
                'username'    => $username,
                'uf'          => $uf,
                'total'       => 0,
                'unidades'    => [],
                'ttl_minutos' => SaudeReservaService::TTL_MINUTOS,
                'message'     => 'Nenhum estabelecimento disponível para reserva nesta UF.',
            ]);
        }

        return $this->json([
            'username'                 => $username,
            'uf'                       => $uf,
            'total'                    => count($itens),
            'retomadas'                => $reserva['retomadas'],
            'novas'                    => $reserva['novas'],
            'expiradas_reaproveitadas' => $reserva['expiradas_reaproveitadas'],
            'ttl_minutos'              => SaudeReservaService::TTL_MINUTOS,
            'expira_em'                => $reserva['expira_em']->format(DATE_ATOM),
            'unidades'                 => array_map($this->serialize(...), $itens),
        ]);
    }

    #[Route('/api/saude/uf/{uf}/renovar', name: 'api_saude_renovar', methods: ['POST'])]
    public function renovar(Request $request, string $uf): JsonResponse
    {
        $user = $this->requireApiUser();
        $uf   = strtoupper(trim($uf));

        if ($erro = $this->validarAcesso($user, $uf, true)) {
            return $erro;
        }

        $data = $this->lerJson($request);

        if ($data === null) {
            return $this->json(['error' => 'Corpo JSON inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $username = trim((string) ($data['username'] ?? ''));

        if ($erro = $this->validarUsername($username)) {
            return $erro;
        }

        $renovadas = $this->reservas->renovar($uf, $username);

        return $this->json([
            'username'    => $username,
            'uf'          => $uf,
            'renovadas'   => $renovadas,
            'ttl_minutos' => SaudeReservaService::TTL_MINUTOS,
            'expira_em'   => (new \DateTimeImmutable())
                ->modify(sprintf('+%d minutes', SaudeReservaService::TTL_MINUTOS))
                ->format(DATE_ATOM),
            'message'     => $renovadas > 0
                ? sprintf('%d reserva(s) renovada(s).', $renovadas)
                : 'Nenhum estabelecimento reservado encontrado para este username nesta UF.',
        ]);
    }

    #[Route('/api/saude/uf/{uf}/liberar', name: 'api_saude_liberar', methods: ['POST'])]
    public function liberar(Request $request, string $uf): JsonResponse
    {
        $user = $this->requireApiUser();
        $uf   = strtoupper(trim($uf));

        if ($erro = $this->validarAcesso($user, $uf, true)) {
            return $erro;
        }

        $data = $this->lerJson($request);

        if ($data === null) {
            return $this->json(['error' => 'Corpo JSON inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $username = trim((string) ($data['username'] ?? ''));

        if ($erro = $this->validarUsername($username)) {
            return $erro;
        }

        $liberadas = $this->reservas->liberar($uf, $username);

        return $this->json([
            'username'  => $username,
            'uf'        => $uf,
            'liberadas' => $liberadas,
            'message'   => $liberadas > 0
                ? sprintf('%d estabelecimento(s) liberado(s) com sucesso.', $liberadas)
                : 'Nenhum estabelecimento reservado encontrado para este username nesta UF.',
        ]);
    }

    #[Route('/api/saude/{id}/status', name: 'api_saude_status', requirements: ['id' => '\d+'], methods: ['PATCH', 'POST'])]
    public function atualizarStatus(Request $request, int $id): JsonResponse
    {
        $user = $this->requireApiUser();
        $item = $this->carregarComPermissao($user, $id);

        if ($item instanceof JsonResponse) {
            return $item;
        }

        $data = $this->lerJson($request);

        if ($data === null) {
            return $this->json(['error' => 'Corpo JSON inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $status   = $data['status'] ?? null;
        $username = trim((string) ($data['username'] ?? ''));

        if (!is_string($status) || !in_array($status, [
            EstabelecimentoSaude::STATUS_CONFIRMADO,
            EstabelecimentoSaude::STATUS_NAO_LOCALIZADO,
            EstabelecimentoSaude::STATUS_PULADO,
        ], true)) {
            return $this->json(['error' => 'Status inválido. Use confirmado, nao_localizado ou pulado.'], Response::HTTP_BAD_REQUEST);
        }

        if ($erro = $this->validarUsername($username)) {
            return $erro;
        }

        if ($item->getReservadoPara() !== null && $item->getReservadoPara() !== $username) {
            return $this->json(['error' => 'Este estabelecimento está reservado para outro usuário.'], Response::HTTP_FORBIDDEN);
        }

        // Valida o snapshot do Place antes de alterar qualquer coisa.
        $snapshot = null;

        if ($status === EstabelecimentoSaude::STATUS_CONFIRMADO && array_key_exists('waze', $data)) {
            try {
                $snapshot = WazeSnapshotNormalizer::normalizar($data['waze']);
            } catch (\InvalidArgumentException $e) {
                return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $agora = new \DateTimeImmutable();

        $item->setStatus($status);
        $item->setUpdatedAt($agora);

        if ($status === EstabelecimentoSaude::STATUS_PULADO) {
            $item->setReservadoPara(null);
            $item->setReservadoEm(null);
        } else {
            $item->setReservadoPara($username);
            $item->setReservadoEm(null);
        }

        if ($snapshot !== null) {
            $item->setWazeSnapshot($snapshot);
            $item->setWazeSnapshotEm($agora);
        }

        $this->em->flush();

        return $this->json([
            'message'         => 'Status atualizado com sucesso.',
            // Só é true depois do flush: o snapshot realmente foi gravado.
            'waze_persistido' => $snapshot !== null,
            'unidade'         => $this->serialize($item),
        ]);
    }

    #[Route('/api/saude/{id}', name: 'api_saude_update', requirements: ['id' => '\d+'], methods: ['PATCH', 'POST'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->requireApiUser();
        $item = $this->carregarComPermissao($user, $id);

        if ($item instanceof JsonResponse) {
            return $item;
        }

        $data = $this->lerJson($request);

        if ($data === null) {
            return $this->json(['error' => 'Corpo JSON inválido.'], Response::HTTP_BAD_REQUEST);
        }

        if (!array_key_exists('link_waze', $data)) {
            return $this->json(['error' => 'Informe o campo link_waze.'], Response::HTTP_BAD_REQUEST);
        }

        $linkWaze = $data['link_waze'] === null ? null : trim((string) $data['link_waze']);
        $linkWaze = $linkWaze === '' ? null : $linkWaze;

        if ($linkWaze !== null && !$this->isWazeLink($linkWaze)) {
            return $this->json(['error' => 'O link_waze deve ser uma URL válida do Waze.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $item->setLinkWaze($linkWaze);
        $item->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json([
            'message' => 'Estabelecimento atualizado com sucesso.',
            'unidade' => $this->serialize($item),
        ]);
    }

    private function requireApiUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Autenticação por token necessária.');
        }

        return $user;
    }

    /** Valida UF, acesso à UF e (para escrita/reserva) a permissão PERM_SAUDE. */
    private function validarAcesso(User $user, string $uf, bool $exigirPermissao = false): ?JsonResponse
    {
        if (!in_array($uf, User::ALL_UFS, true)) {
            return $this->json(['error' => 'UF inválida.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$user->canAccessUf($uf)) {
            return $this->json(['error' => 'Você não tem acesso a esta UF.'], Response::HTTP_FORBIDDEN);
        }

        if ($exigirPermissao && !$user->hasPermission(User::PERMISSION_SAUDE)) {
            return $this->json(['error' => 'Você não tem permissão para estabelecimentos de saúde.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function carregarComPermissao(User $user, int $id): EstabelecimentoSaude|JsonResponse
    {
        if (!$user->hasPermission(User::PERMISSION_SAUDE)) {
            return $this->json(['error' => 'Você não tem permissão para atualizar estabelecimentos de saúde.'], Response::HTTP_FORBIDDEN);
        }

        $item = $this->em->find(EstabelecimentoSaude::class, $id);

        if (!$item instanceof EstabelecimentoSaude) {
            return $this->json(['error' => 'Estabelecimento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$user->canAccessUf((string) $item->getUf())) {
            return $this->json(['error' => 'Você não tem acesso a esta UF.'], Response::HTTP_FORBIDDEN);
        }

        return $item;
    }

    private function validarUsername(string $username, bool $limitarTamanho = false): ?JsonResponse
    {
        if ($username === '') {
            return $this->json(['error' => 'O campo username é obrigatório.'], Response::HTTP_BAD_REQUEST);
        }

        if ($limitarTamanho && mb_strlen($username) > 100) {
            return $this->json(['error' => 'O username deve ter no máximo 100 caracteres.'], Response::HTTP_BAD_REQUEST);
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function lerJson(Request $request): ?array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : null;
    }

    /** @return array<string, mixed> */
    private function serialize(EstabelecimentoSaude $e): array
    {
        return [
            'id'               => $e->getId(),
            'cnes'             => $e->getCnes(),
            'nome'             => $e->getNomeFantasia(),
            'ativo'            => $e->isAtivo(),
            'uf'               => $e->getUf(),
            'municipio'        => $e->getMunicipio(),
            'logradouro'       => $e->getLogradouro(),
            'telefone'         => $e->getTelefone(),
            'latitude'         => $e->getLatitude(),
            'longitude'        => $e->getLongitude(),
            'link_waze'        => $e->getLinkWaze(),
            'status'           => $e->getStatus(),
            'reservado_para'   => $e->getReservadoPara(),
            'reservado_em'     => $e->getReservadoEm()?->format(DATE_ATOM),
            'updated_at'       => $e->getUpdatedAt()?->format(DATE_ATOM),
            'waze_snapshot_em' => $e->getWazeSnapshotEm()?->format(DATE_ATOM),
        ];
    }

    private function isWazeLink(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && (bool) preg_match('/^https?:\/\/([a-z0-9-]+\.)?waze\.com\//i', $url);
    }
}
