<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Notificacao;
use App\Entity\Solicitacao;
use App\Entity\SolicitacaoComentario;
use App\Entity\SolicitacaoHistorico;
use App\Entity\User;
use App\Entity\WmeDispositivo;
use App\Exception\WmeApiException;
use App\Message\EnviarEmailSolicitacao;
use App\Message\EnviarEmailWmeVerificacao;
use App\Repository\TipoSolicitacaoConfigRepository;
use App\Security\WmeDeviceAuthenticator;
use App\Service\WmeFormularioService;
use App\Service\SolicitacaoIntervaloService;
use App\Service\TratativaAcessoService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/wme', name: 'api_wme_')]
final class WmeApiController extends AbstractController
{
    private const VERIFICACAO_VALIDADE = '+60 minutes';
    private const REENVIO_SEGUNDOS = 300;
    private const MAX_CADASTROS_POR_IP_H = 30;
    private const MAX_PENDENTES_POR_USER = 3;
    private const MAX_SOLICITACOES_LISTA = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WmeFormularioService $forms,
        private readonly SolicitacaoIntervaloService $intervalo,
        private readonly TipoSolicitacaoConfigRepository $configs,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly TratativaAcessoService $acesso,
    ) {
    }

    private function auth(Request $request, bool $exigirAtivo = true): array
    {
        $user = $this->getUser();
        $estado = $request->attributes->get(
            WmeDeviceAuthenticator::ATTR_ESTADO
        );

        if (
            !$user instanceof User
            || !in_array($estado, [
                WmeDispositivo::ESTADO_ATIVO,
                WmeDispositivo::ESTADO_PENDENTE,
            ], true)
        ) {
            throw new WmeApiException(
                401,
                'nao_autenticado',
                'Token pessoal obrigatório.'
            );
        }

        if ($user->isRejected()) {
            throw new WmeApiException(
                403,
                'conta_bloqueada',
                'Conta bloqueada.'
            );
        }

        if ($exigirAtivo && $estado !== WmeDispositivo::ESTADO_ATIVO) {
            throw new WmeApiException(
                403,
                'email_nao_confirmado',
                'Confirme seu e-mail para continuar.'
            );
        }

        $device = $request->attributes->get(
            WmeDeviceAuthenticator::ATTR_DISPOSITIVO
        );

        return [
            $user,
            $device instanceof WmeDispositivo ? $device : null,
            (string) $estado,
        ];
    }

    private function run(callable $action): JsonResponse
    {
        try {
            $result = $action();

            $response = $result instanceof JsonResponse
                ? $result
                : $this->json($result);
        } catch (WmeApiException $exception) {
            $response = $this->json(
                array_merge([
                    'codigo' => $exception->getCodigo(),
                    'message' => $exception->getMessage(),
                ], $exception->getExtra()),
                $exception->getStatusCode()
            );
        } catch (HttpException $exception) {
            $response = $this->json([
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        } catch (\Throwable $exception) {
            $this->logger->error('WME API: {class}', [
                'class' => $exception::class,
                'exception' => $exception,
            ]);

            $response = $this->json([
                'codigo' => 'erro_interno',
                'message' => 'Falha interna. Consulte o envio antes de repetir.',
            ], 500);
        }

        if ($response->getStatusCode() === 429) {
            $body = json_decode((string) $response->getContent(), true);
            if (is_array($body) && ($body['codigo'] ?? null) === 'intervalo_envio') {
                $response->headers->set('Retry-After', (string) max(1, (int) ($body['aguardarSegundos'] ?? 1)));
            }
        }

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $dot = strrpos($domain, '.');
        $host = $dot === false ? $domain : substr($domain, 0, $dot);
        $tld = $dot === false ? '' : substr($domain, $dot);

        return mb_substr($local, 0, 1)
            . '***@'
            . mb_substr($host, 0, 1)
            . '***'
            . $tld;
    }

    private function enviarVerificacao(
        WmeDispositivo $device,
        string $verificador
    ): bool {
        try {
            $this->bus->dispatch(new EnviarEmailWmeVerificacao(
                (int) $device->getId(),
                $verificador
            ));

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error(
                'WME: e-mail de verificação não enviado (dispositivo {id})',
                [
                    'id' => $device->getId(),
                    'erro' => $exception->getMessage(),
                ]
            );

            return false;
        }
    }

    #[Route('/registrar', name: 'registrar', methods: ['POST'])]
    public function registrar(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $token = trim((string) $request->headers->get('X-API-TOKEN'));

            if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
                throw new WmeApiException(
                    400,
                    'token_invalido',
                    'Token ausente ou em formato inválido.'
                );
            }

            $raw = $request->getContent();

            if (strlen($raw) > 4096) {
                throw new WmeApiException(
                    400,
                    'payload_invalido',
                    'Payload maior que 4 KB.'
                );
            }

            try {
                $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new WmeApiException(
                    400,
                    'payload_invalido',
                    'JSON inválido.'
                );
            }

            if (!is_array($payload)) {
                throw new WmeApiException(
                    400,
                    'payload_invalido',
                    'Payload inválido.'
                );
            }

            $email = mb_strtolower($this->str($payload, 'email'));
            $apelido = $this->str($payload, 'wazeNickname');
            $nome = $this->str($payload, 'nome');
            $errors = [];

            if (
                $email === ''
                || strlen($email) > 180
                || !filter_var($email, FILTER_VALIDATE_EMAIL)
            ) {
                $errors['email'] = ['E-mail inválido.'];
            }

            if (
                $apelido === ''
                || mb_strlen($apelido) > 60
                || preg_match('/[\x00-\x1F\x7F<>]/u', $apelido) === 1
            ) {
                $errors['wazeNickname'] = ['Apelido do WME inválido.'];
            }

            if (
                $nome !== ''
                && (
                    mb_strlen($nome) > 120
                    || preg_match('/[\x00-\x1F\x7F<>]/u', $nome) === 1
                )
            ) {
                $errors['nome'] = ['Nome inválido.'];
            }

            if ($errors !== []) {
                throw new WmeApiException(
                    422,
                    'validacao',
                    'Revise os campos.',
                    ['errors' => $errors]
                );
            }

            if ($nome === '') {
                $nome = $apelido;
            }

            $hash = hash('sha256', $token);
            $database = $this->em->getConnection();

            $existente = $this->em->getRepository(WmeDispositivo::class)
                ->findOneBy(['tokenHash' => $hash]);

            if ($existente instanceof WmeDispositivo) {
                throw new WmeApiException(
                    409,
                    'token_ja_registrado',
                    'Este token já foi registrado.',
                    ['estado' => $existente->getEstado()]
                );
            }

            $ipHash = hash_hmac(
                'sha256',
                (string) ($request->getClientIp() ?? ''),
                (string) $this->getParameter('kernel.secret')
            );

            $desde = (new \DateTimeImmutable('-1 hour'))
                ->format('Y-m-d H:i:s');

            $cadastros = (int) $database->fetchOne(
                'SELECT COUNT(*) FROM wme_dispositivo
                 WHERE ip_hash = ? AND criado_em > ?',
                [$ipHash, $desde]
            );

            if ($cadastros >= self::MAX_CADASTROS_POR_IP_H) {
                throw new WmeApiException(
                    429,
                    'muitas_tentativas',
                    'Muitas tentativas. Aguarde um pouco.'
                );
            }

            $user = $this->em->getRepository(User::class)
                ->findOneBy(['email' => $email]);

            if ($user instanceof User) {
                $pendentes = (int) $database->fetchOne(
                    'SELECT COUNT(*) FROM wme_dispositivo
                     WHERE user_id = ? AND estado = ? AND criado_em > ?',
                    [$user->getId(), WmeDispositivo::ESTADO_PENDENTE, $desde]
                );

                if ($pendentes >= self::MAX_PENDENTES_POR_USER) {
                    throw new WmeApiException(
                        429,
                        'muitas_tentativas',
                        'Muitas tentativas. Aguarde um pouco.'
                    );
                }
            } else {
                $user = (new User())
                    ->setEmail($email)
                    ->setName($nome)
                    ->setWazeNickname($apelido)
                    ->setStatus(User::STATUS_PENDING)
                    ->setRoles([])
                    ->setAllowedUfs([]);

                $this->em->persist($user);
            }

            $device = new WmeDispositivo($hash, $user);
            $device
                ->setApelidoDeclarado($apelido)
                ->setScriptVersion(
                    substr($this->str($payload, 'scriptVersion'), 0, 20) ?: null
                )
                ->setIpHash($ipHash);

            $verificador = bin2hex(random_bytes(24));

            $device->definirVerificacao(
                hash('sha256', $verificador),
                new \DateTimeImmutable(self::VERIFICACAO_VALIDADE)
            );

            $this->em->persist($device);

            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                throw new WmeApiException(
                    409,
                    'tente_novamente',
                    'Conflito ao registrar. Tente novamente.'
                );
            }

            $enviado = $this->enviarVerificacao($device, $verificador);

            return new JsonResponse([
                'estado' => WmeDispositivo::ESTADO_PENDENTE,
                'emailMascarado' => $this->maskEmail($email),
                'emailEnviado' => $enviado,
                'expiraEm' => $device->getVerificacaoExpiraEm()?->format(DATE_ATOM),
                'reenvioEm' => (
                    new \DateTimeImmutable(
                        '+' . self::REENVIO_SEGUNDOS . ' seconds'
                    )
                )->format(DATE_ATOM),
            ], 202);
        });
    }

    #[Route('/verificacao/reenviar', name: 'reenviar', methods: ['POST'])]
    public function reenviar(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            [$user, $device, $estado] = $this->auth($request, false);

            if ($estado === WmeDispositivo::ESTADO_ATIVO || $device === null) {
                return ['estado' => WmeDispositivo::ESTADO_ATIVO];
            }

            $ultimo = $device->getVerificacaoEnviadaEm();

            if ($ultimo !== null) {
                $espera = self::REENVIO_SEGUNDOS
                    - (time() - $ultimo->getTimestamp());

                if ($espera > 0) {
                    throw new WmeApiException(
                        429,
                        'aguarde',
                        'Aguarde para reenviar o e-mail.',
                        ['aguardarSegundos' => $espera]
                    );
                }
            }

            $verificador = bin2hex(random_bytes(24));

            $device->definirVerificacao(
                hash('sha256', $verificador),
                new \DateTimeImmutable(self::VERIFICACAO_VALIDADE)
            );

            $this->em->flush();

            return [
                'estado' => WmeDispositivo::ESTADO_PENDENTE,
                'emailMascarado' => $this->maskEmail($user->getEmail()),
                'emailEnviado' => $this->enviarVerificacao($device, $verificador),
                'expiraEm' => $device->getVerificacaoExpiraEm()?->format(DATE_ATOM),
                'reenvioEm' => (
                    new \DateTimeImmutable(
                        '+' . self::REENVIO_SEGUNDOS . ' seconds'
                    )
                )->format(DATE_ATOM),
            ];
        });
    }

    #[Route(
        '/confirmar/{id}/{verificador}',
        name: 'confirmar',
        methods: ['GET', 'POST'],
        requirements: ['id' => '\d+', 'verificador' => '[a-f0-9]{48}']
    )]
    public function confirmar(
        int $id,
        string $verificador,
        Request $request
    ): Response {
        $device = $this->em->find(WmeDispositivo::class, $id);

        $hashOk = $device instanceof WmeDispositivo
            && $device->isPendente()
            && $device->getVerificacaoHash() !== null
            && hash_equals(
                $device->getVerificacaoHash(),
                hash('sha256', $verificador)
            );

        if (!$hashOk) {
            return $this->pagina(
                'Link inválido ou já usado',
                'Peça um novo e-mail de confirmação no script do WME.',
                400
            );
        }

        if (
            $device->getVerificacaoExpiraEm() === null
            || $device->getVerificacaoExpiraEm() < new \DateTimeImmutable()
        ) {
            return $this->pagina(
                'Link expirado',
                'Volte ao WME e peça um novo e-mail de confirmação.',
                410
            );
        }

        if ($device->getUser()->isRejected()) {
            return $this->pagina(
                'Conta indisponível',
                'Esta conta não pode usar o script. Fale com a administração.',
                403
            );
        }

        if ($request->isMethod('POST')) {
            $device->ativar();
            $this->em->flush();

            return $this->pagina(
                'E-mail confirmado',
                'Pronto! Volte ao WME: o script detecta a confirmação sozinho.',
                200
            );
        }

        return $this->pagina(
            'Confirmar e-mail',
            'Confirme que '
                . $this->maskEmail($device->getUser()->getEmail())
                . ' é o seu e-mail para liberar o script de solicitações do WME.',
            200,
            true
        );
    }

    private function pagina(
        string $titulo,
        string $texto,
        int $status,
        bool $comBotao = false
    ): Response {
        $tituloSeguro = htmlspecialchars(
            $titulo,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $textoSeguro = htmlspecialchars(
            $texto,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $botao = $comBotao
            ? '<form method="post" action="">'
                . '<button type="submit">Confirmar e-mail</button></form>'
            : '';

        $html = '<!DOCTYPE html><html lang="pt-BR"><head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $tituloSeguro . ' — ToolboxWaze</title>'
            . '<style>'
            . 'body{font:16px/1.5 system-ui,sans-serif;background:#f4f8f5;'
            . 'color:#16342b;display:grid;place-items:center;min-height:100vh;'
            . 'margin:0;padding:16px}'
            . 'main{background:#fff;border:1px solid #dce9e1;border-radius:14px;'
            . 'padding:28px;max-width:440px;box-shadow:0 8px 30px #0001}'
            . 'h1{font-size:1.3rem;margin:0 0 10px}'
            . 'p{margin:0 0 18px;color:#40594d}'
            . 'button{background:#176d4c;color:#fff;border:0;border-radius:9px;'
            . 'padding:12px 22px;font:inherit;font-weight:700;cursor:pointer}'
            . '</style></head><body><main>'
            . '<h1>' . $tituloSeguro . '</h1>'
            . '<p>' . $textoSeguro . '</p>'
            . $botao
            . '</main></body></html>';

        $response = new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);

        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'unsafe-inline'; "
                . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
        );

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/dispositivo/revogar', name: 'revogar', methods: ['POST'])]
    public function revogar(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            [, $device] = $this->auth($request, false);

            if ($device !== null) {
                $device->revogar();
                $this->em->flush();
            }

            return ['estado' => WmeDispositivo::ESTADO_REVOGADO];
        });
    }

    #[Route('/bootstrap', name: 'bootstrap', methods: ['GET'])]
    public function bootstrap(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            [$user, $device, $estado] = $this->auth($request, false);

            if ($estado === WmeDispositivo::ESTADO_PENDENTE) {
                $ultimo = $device?->getVerificacaoEnviadaEm();
                $reenvio = $ultimo?->modify(
                    '+' . self::REENVIO_SEGUNDOS . ' seconds'
                );

                return [
                    'estado' => WmeDispositivo::ESTADO_PENDENTE,
                    'usuario' => [
                        'emailMascarado' => $this->maskEmail($user->getEmail()),
                        'wazeNickname' => $device?->getApelidoDeclarado(),
                    ],
                    'expiraEm' => $device?->getVerificacaoExpiraEm()
                        ?->format(DATE_ATOM),
                    'reenvioEm' => $reenvio?->format(DATE_ATOM),
                    'formularios' => [],
                ];
            }

            return [
                'estado' => WmeDispositivo::ESTADO_ATIVO,
                'usuario' => [
                    'id' => $user->getId(),
                    'nome' => $user->getName(),
                    'wazeNickname' => $user->getWazeNickname(),
                    'aprovado' => $user->isApproved(),
                ],
                'formularios' => $this->forms->available($user),
                // Só orienta a interface; cada endpoint de tratativa revalida no servidor.
                'capacidades' => $this->capacidades($user),
            ];
        });
    }

    #[Route('/formularios/{slug}', name: 'formulario', methods: ['GET'])]
    public function formulario(string $slug, Request $request): JsonResponse
    {
        return $this->run(function () use ($slug, $request) {
            [$user] = $this->auth($request);

            return $this->forms->schema($this->forms->get($slug, $user), $user);
        });
    }

    #[Route('/solicitacoes', name: 'minhas', methods: ['GET'])]
    public function minhas(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            [$user] = $this->auth($request);

            $solicitacoes = $this->em->getRepository(Solicitacao::class)
                ->findBy(
                    ['solicitanteEmail' => $user->getEmail()],
                    ['criadaEm' => 'DESC'],
                    self::MAX_SOLICITACOES_LISTA
                );

            return [
                'solicitacoes' => array_map(
                    fn (Solicitacao $solicitacao): array =>
                        $this->solicitacaoResumo($solicitacao),
                    $solicitacoes
                ),
            ];
        });
    }

    #[Route(
        '/solicitacoes/{id}/status',
        name: 'status',
        methods: ['GET'],
        requirements: ['id' => '\d+']
    )]
    public function status(int $id, Request $request): JsonResponse
    {
        $requestId = bin2hex(random_bytes(8));

        $diagnostico = [
            'controller_recebeu_requisicao',
        ];

        $response = $this->run(function () use (
            $id,
            $request,
            $requestId,
            &$diagnostico
        ) {
            $this->logger->info('WME status: entrada', [
                'requestId' => $requestId,
                'solicitacaoId' => $id,
                'rota' => $request->attributes->get('_route'),
            ]);

            [$user] = $this->auth($request);
            $diagnostico[] = 'autenticacao_ok';

            $solicitacao = $this->em->find(Solicitacao::class, $id);

            // A resposta pública não distingue registro inexistente de
            // acesso negado, evitando revelar solicitações de outras contas.
            if (
                !$solicitacao instanceof Solicitacao
                || strcasecmp(
                    (string) $solicitacao->getSolicitanteEmail(),
                    $user->getEmail()
                ) !== 0
            ) {
                $this->logger->warning('WME status: consulta não autorizada', [
                    'requestId' => $requestId,
                    'solicitacaoId' => $id,
                    'usuarioId' => $user->getId(),
                    'motivo' => $solicitacao instanceof Solicitacao
                        ? 'proprietario_diferente'
                        : 'registro_inexistente',
                ]);

                $diagnostico[] = 'consulta_nao_disponivel_para_esta_conta';

                throw new WmeApiException(
                    404,
                    'solicitacao_nao_encontrada',
                    'Solicitação não encontrada.'
                );
            }

            $diagnostico[] = 'consulta_autorizada';

            $comentarios = $solicitacao->getComentarios()
                ->filter(
                    fn (SolicitacaoComentario $comentario): bool =>
                        !$comentario->isInterno()
                )
                ->toArray();

            $diagnostico[] = 'comentarios_publicos_carregados';

            return [
                'id' => $solicitacao->getId(),
                'status' => $solicitacao->getStatus(),
                'statusLabel' => $solicitacao->getStatusLabel(),
                'criadaEm' => $solicitacao->getCriadaEm()->format(DATE_ATOM),
                'url' => '/solicitacoes/' . $solicitacao->getId(),
                'comentarios' => array_values(array_map(
                    fn (SolicitacaoComentario $comentario): array => [
                        'autor' => $comentario->getAutorNome(),
                        'texto' => $comentario->getMensagem(),
                        'criadoEm' => $comentario->getCriadoEm()->format(DATE_ATOM),
                    ],
                    $comentarios
                )),
            ];
        });

        $data = json_decode(
            (string) $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $data['requestId'] = $requestId;
        $data['origem'] = 'WmeApiController::status';
        $data['diagnostico'] = $diagnostico;

        $response->setData($data);
        $response->headers->set('X-Request-ID', $requestId);

        $this->logger->info('WME status: resposta', [
            'requestId' => $requestId,
            'solicitacaoId' => $id,
            'httpStatus' => $response->getStatusCode(),
            'diagnostico' => $diagnostico,
        ]);

        return $response;
    }

    #[Route('/solicitacoes/alteracoes', name: 'alteracoes', methods: ['GET'])]
    public function alteracoes(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            [$user] = $this->auth($request);

            $since = $request->query->get('since');
            $referencia = null;

            if (is_string($since) && $since !== '') {
                try {
                    $referencia = new \DateTimeImmutable($since);
                } catch (\Exception) {
                    throw new WmeApiException(
                        400,
                        'parametro_invalido',
                        'O parâmetro since é inválido.'
                    );
                }
            }

            $builder = $this->em->getRepository(Solicitacao::class)
                ->createQueryBuilder('s')
                ->where('s.solicitanteEmail = :email')
                ->setParameter('email', $user->getEmail())
                ->orderBy('s.atualizadaEm', 'ASC')
                ->setMaxResults(self::MAX_SOLICITACOES_LISTA);

            if ($referencia !== null) {
                $builder
                    ->andWhere('s.atualizadaEm > :since')
                    ->setParameter('since', $referencia);
            }

            $solicitacoes = $builder->getQuery()->getResult();

            return [
                'servidorEm' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'alteracoes' => array_map(
                    fn (Solicitacao $solicitacao): array => [
                        'id' => $solicitacao->getId(),
                        'status' => $solicitacao->getStatus(),
                        'statusLabel' => $solicitacao->getStatusLabel(),
                        'atualizadoEm' => $solicitacao->getAtualizadaEm()
                            ->format(DATE_ATOM),
                        'resumo' => 'A solicitação #'
                            . $solicitacao->getId()
                            . ' foi atualizada.',
                        'url' => '/solicitacoes/' . $solicitacao->getId(),
                    ],
                    $solicitacoes
                ),
            ];
        });
    }

    private function solicitacaoResumo(Solicitacao $solicitacao): array
    {
        return [
            'id' => $solicitacao->getId(),
            'formulario' => $solicitacao->getFormulario()?->getNome()
                ?? $solicitacao->getTipoLabel(),
            'status' => $solicitacao->getStatus(),
            'statusLabel' => $solicitacao->getStatusLabel(),
            'criadaEm' => $solicitacao->getCriadaEm()->format(DATE_ATOM),
            'url' => '/solicitacoes/' . $solicitacao->getId(),
        ];
    }

    /** @return array<string, mixed> */
    private function capacidades(User $user): array
    {
        try {
            return $this->acesso->capacidades($user);
        } catch (\Throwable $exception) {
            $this->logger->error('WME capacidades: {erro}', ['erro' => $exception->getMessage()]);

            return ['tratar' => false, 'admin' => false, 'champ' => false, 'tipos' => []];
        }
    }

    private function key(User $user, string $uuid): string
    {
        if (!preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-'
                . '[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di',
            $uuid
        )) {
            throw new WmeApiException(
                400,
                'chave_invalida',
                'clientRequestId inválido.'
            );
        }

        return $user->getId() . ':' . strtolower($uuid);
    }

    private function lock(User $user): void
    {
        $this->em->getConnection()
            ->executeQuery(
                'SELECT id FROM `user` WHERE id = ? FOR UPDATE',
                [$user->getId()]
            )
            ->fetchOne();
    }

    private function outcome(array $row): array
    {
        if ($row['estado'] !== 'concluido') {
            return ['estado' => $row['estado']];
        }

        $solicitacao = $this->em->find(Solicitacao::class, (int) $row['solicitacao_id']);
        $user = $this->getUser();
        $intervalo = ($solicitacao?->getFormulario() !== null && $user instanceof User)
            ? $this->intervalo->status($user, $solicitacao->getFormulario())
            : ['aguardarSegundos' => 0, 'proximoEnvioEm' => null];

        return [
            'aguardarSegundos' => $intervalo['aguardarSegundos'],
            'proximoEnvioEm' => $intervalo['proximoEnvioEm'],
            'estado' => 'concluido',
            'solicitacao' => [
                'id' => (int) $row['solicitacao_id'],
                'url' => $this->generateUrl('solicitacao_detalhe', [
                    'id' => $row['solicitacao_id'],
                ]),
            ],
        ];
    }

    #[Route('/envios/{clientRequestId}', name: 'envio', methods: ['GET'])]
    public function envio(
        string $clientRequestId,
        Request $request
    ): JsonResponse {
        return $this->run(function () use ($clientRequestId, $request) {
            [$user] = $this->auth($request);

            $key = $this->key($user, $clientRequestId);
            $database = $this->em->getConnection();
            $database->beginTransaction();

            try {
                $this->lock($user);

                $row = $database->fetchAssociative(
                    'SELECT * FROM wme_envio WHERE id = ?',
                    [$key]
                );

                if ($row === false) {
                    $database->insert('wme_envio', [
                        'id' => $key,
                        'user_id' => $user->getId(),
                        'hash' => str_repeat('0', 64),
                        'estado' => 'nao_criado',
                        'solicitacao_id' => null,
                        'criado_em' => date('Y-m-d H:i:s'),
                    ]);

                    $row = ['estado' => 'nao_criado'];
                }

                $database->commit();

                return $this->outcome($row);
            } catch (\Throwable $exception) {
                if ($database->isTransactionActive()) {
                    $database->rollBack();
                }

                throw $exception;
            }
        });
    }

    #[Route('/solicitacoes', name: 'criar', methods: ['POST'])]
    public function criar(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            [$user] = $this->auth($request);

            $raw = $request->request->get('payload');

            if (!is_string($raw) || strlen($raw) > 262144) {
                throw new WmeApiException(
                    400,
                    'payload_invalido',
                    'Payload ausente ou maior que 256 KB.'
                );
            }

            try {
                $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new WmeApiException(
                    400,
                    'payload_invalido',
                    'JSON inválido.'
                );
            }

            if (
                !is_array($payload)
                || !is_string($payload['formulario'] ?? null)
                || !is_string($payload['clientRequestId'] ?? null)
                || !is_array($payload['respostas'] ?? null)
            ) {
                throw new WmeApiException(
                    400,
                    'payload_invalido',
                    'Payload inválido.'
                );
            }

            $key = $this->key($user, $payload['clientRequestId']);
            $form = $this->forms->get($payload['formulario'], $user);
            $schema = $this->forms->schema($form, $user);

            if (($payload['versaoFormulario'] ?? '') !== $schema['versao']) {
                throw new WmeApiException(
                    422,
                    'formulario_desatualizado',
                    'Formulário atualizado. Recarregue sua definição.'
                );
            }

            [$clean, $files, $errors] = $this->forms->validate(
                $schema,
                $payload['respostas'],
                $request->files->all('anexos')
            );

            if ($errors !== []) {
                throw new WmeApiException(
                    422,
                    'validacao',
                    'Revise os campos.',
                    ['errors' => $errors]
                );
            }

            $digest = [];

            foreach ($files as $campo => $lista) {
                foreach ($lista as $arquivo) {
                    $digest[$campo][] = hash_file(
                        'sha256',
                        $arquivo->getPathname()
                    );
                }
            }

            $hash = hash(
                'sha256',
                json_encode([$payload, $digest], JSON_THROW_ON_ERROR)
            );

            $database = $this->em->getConnection();
            $database->beginTransaction();

            $movidos = [];
            $responsaveis = [];

            try {
                $this->lock($user);

                $row = $database->fetchAssociative(
                    'SELECT * FROM wme_envio WHERE id = ?',
                    [$key]
                );

                if ($row !== false) {
                    if (
                        $row['estado'] === 'nao_criado'
                        || !hash_equals($row['hash'], $hash)
                    ) {
                        throw new WmeApiException(
                            409,
                            'chave_reutilizada',
                            'Chave encerrada ou reutilizada com outro conteúdo.'
                        );
                    }

                    $database->commit();

                    return $this->outcome($row);
                }

                $recentes = (int) $database->fetchOne(
                    'SELECT COUNT(*) FROM wme_envio
                     WHERE user_id = ? AND estado = ? AND criado_em > ?',
                    [
                        $user->getId(),
                        'concluido',
                        date('Y-m-d H:i:s', time() - 60),
                    ]
                );

                if ($recentes >= 10) {
                    throw new WmeApiException(
                        429,
                        'limite_minuto',
                        'Limite de dez envios por minuto.'
                    );
                }

                $this->forms->get($payload['formulario'], $user);

                // The user row is locked above, after checking idempotency.
                $intervalo = $this->intervalo->status($user, $form);
                if ($intervalo['aguardarSegundos'] > 0) {
                    throw new WmeApiException(
                        429,
                        'intervalo_envio',
                        'Aguarde ' . $intervalo['aguardarSegundos']
                            . ' segundos para enviar este formulário novamente.',
                        $intervalo
                    );
                }

                $anexos = [];
                $diretorio = $this->getParameter('kernel.project_dir')
                    . '/var/wme_uploads';

                foreach ($files as $campo => $lista) {
                    foreach ($lista as $arquivo) {
                        if (
                            !is_dir($diretorio)
                            && !mkdir($diretorio, 0700, true)
                            && !is_dir($diretorio)
                        ) {
                            throw new \RuntimeException('Storage indisponível.');
                        }

                        $mime = $arquivo->getMimeType();
                        $extensao = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/webp' => 'webp',
                        ][$mime] ?? 'bin';

                        $nome = bin2hex(random_bytes(20)) . '.' . $extensao;
                        $tamanho = $arquivo->getSize();

                        $arquivo->move($diretorio, $nome);

                        $movidos[] = $diretorio . '/' . $nome;
                        $anexos[] = [
                            'campo' => $campo,
                            'nome' => $nome,
                            'mime' => $mime,
                            'bytes' => $tamanho,
                        ];
                    }
                }

                $solicitacao = new Solicitacao();
                $solicitacao
                    ->setFormulario($form)
                    ->setTipo($form->getSlug())
                    ->setStatus(Solicitacao::STATUS_PENDENTE)
                    ->setSolicitanteNome(
                        $user->getName() ?: $user->getEmail()
                    )
                    ->setSolicitanteUsuario(
                        $user->getWazeNickname() ?: $user->getEmail()
                    )
                    ->setSolicitanteEmail($user->getEmail())
                    ->setDadosDinamicos($clean)
                    ->setDados([
                        '_wme' => [
                            'contexto' => $payload['contexto'] ?? null,
                            'anexos' => $anexos,
                            'clientRequestId' => $payload['clientRequestId'],
                        ],
                    ]);

                $config = $this->configs->findByTipo($form->getSlug());

                if ($config !== null) {
                    foreach ($config->getResponsaveisDefault() as $responsavel) {
                        $solicitacao->addResponsavel($responsavel);
                        $responsaveis[] = $responsavel;
                    }
                }

                $historico = new SolicitacaoHistorico();
                $historico->setSolicitacao($solicitacao);
                $historico->setStatusAnterior(null);
                $historico->setStatusNovo(Solicitacao::STATUS_PENDENTE);
                $historico->setAutor($user);
                $historico->setNota('Solicitação criada via API WME.');

                $solicitacao->addHistorico($historico);

                $this->em->persist($solicitacao);
                $this->em->persist($historico);
                $this->em->flush();

                foreach ($responsaveis as $responsavel) {
                    $notificacao = new Notificacao();
                    $notificacao->setUsuario($responsavel);
                    $notificacao->setSolicitacao($solicitacao);
                    $notificacao->setTipo(Notificacao::TIPO_NOVA_SOLICITACAO);
                    $notificacao->setMensagem(
                        'Nova solicitação WME #' . $solicitacao->getId()
                    );

                    $this->em->persist($notificacao);
                }

                $this->em->flush();

                $database->insert('wme_envio', [
                    'id' => $key,
                    'user_id' => $user->getId(),
                    'hash' => $hash,
                    'estado' => 'concluido',
                    'solicitacao_id' => $solicitacao->getId(),
                    'criado_em' => date('Y-m-d H:i:s'),
                ]);

                $database->commit();
            } catch (\Throwable $exception) {
                if ($database->isTransactionActive()) {
                    $database->rollBack();
                }

                foreach ($movidos as $caminho) {
                    @unlink($caminho);
                }

                $this->em->clear();

                throw $exception;
            }

            try {
                $this->bus->dispatch(new EnviarEmailSolicitacao(
                    'confirmacao',
                    $solicitacao->getId()
                ));

                foreach ($responsaveis as $responsavel) {
                    $this->bus->dispatch(new EnviarEmailSolicitacao(
                        'responsavel',
                        $solicitacao->getId(),
                        $responsavel->getId()
                    ));
                }
            } catch (\Throwable $exception) {
                $this->logger->error(
                    'WME: e-mail não despachado para solicitação {id}',
                    [
                        'id' => $solicitacao->getId(),
                        'erro' => $exception->getMessage(),
                    ]
                );
            }

            return $this->outcome([
                'estado' => 'concluido',
                'solicitacao_id' => $solicitacao->getId(),
            ]);
        });
    }

    #[Route(
        '/solicitacoes/{id}/anexos/{nome}',
        name: 'anexo',
        methods: ['GET'],
        requirements: [
            'id' => '\d+',
            'nome' => '[a-f0-9]{40}\.(jpg|png|webp)',
        ]
    )]
    public function anexo(
        int $id,
        string $nome,
        Request $request
    ): Response {
        try {
            [$user] = $this->auth($request);
        } catch (WmeApiException $exception) {
            return $this->json([
                'codigo' => $exception->getCodigo(),
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        }

        $solicitacao = $this->em->find(Solicitacao::class, $id);

        if ($solicitacao === null) {
            throw new HttpException(404);
        }

        $dono = strcasecmp(
            (string) $solicitacao->getSolicitanteEmail(),
            $user->getEmail()
        ) === 0;

        if (!$dono && !$this->isGranted('SOLICITACAO_VER', $solicitacao)) {
            throw new HttpException(403);
        }

        $meta = null;

        foreach (($solicitacao->getDados()['_wme']['anexos'] ?? []) as $anexo) {
            if (($anexo['nome'] ?? null) === $nome) {
                $meta = $anexo;
                break;
            }
        }

        if ($meta === null) {
            throw new HttpException(404);
        }

        $response = new BinaryFileResponse(
            $this->getParameter('kernel.project_dir')
                . '/var/wme_uploads/'
                . $nome
        );

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $nome
        );

        $response->headers->set('Content-Type', $meta['mime']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}