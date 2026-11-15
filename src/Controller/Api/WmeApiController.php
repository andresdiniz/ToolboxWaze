<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Notificacao;
use App\Entity\Solicitacao;
use App\Entity\SolicitacaoHistorico;
use App\Entity\User;
use App\Entity\WmeDispositivo;
use App\Exception\WmeApiException;
use App\Message\EnviarEmailSolicitacao;
use App\Message\EnviarEmailWmeVerificacao;
use App\Repository\TipoSolicitacaoConfigRepository;
use App\Security\WmeDeviceAuthenticator;
use App\Service\WmeFormularioService;
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

/**
 * API única do userscript WME (/api/wme/*).
 *
 * Fluxo de conexão (sem conta prévia no site):
 *   1. O userscript gera um token aleatório (64 hex) e o guarda localmente.
 *   2. POST /registrar {email, wazeNickname}  → cria a conta (status "pending") se o e-mail
 *      ainda não existir e registra o dispositivo como "pendente"; envia e-mail de confirmação.
 *   3. O dono do e-mail abre o link (GET mostra botão, POST confirma) → dispositivo "ativo".
 *   4. Com o dispositivo ativo, o script lista formulários, envia e consulta solicitações.
 *
 * Autenticação: WmeDeviceAuthenticator (header X-API-TOKEN). /registrar e /confirmar são públicos.
 */
#[Route('/api/wme', name: 'api_wme_')]
final class WmeApiController extends AbstractController
{
    private const VERIFICACAO_VALIDADE   = '+60 minutes';
    private const REENVIO_SEGUNDOS       = 300;
    private const MAX_CADASTROS_POR_IP_H = 30;
    private const MAX_PENDENTES_POR_USER = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WmeFormularioService $forms,
        private readonly TipoSolicitacaoConfigRepository $configs,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ── Infra ────────────────────────────────────────────────────────────────

    /**
     * Usuário autenticado pelo token do dispositivo (ou token legado do site).
     *
     * @return array{0: User, 1: ?WmeDispositivo, 2: string} [usuário, dispositivo, estado]
     */
    private function auth(Request $r, bool $exigirAtivo = true): array
    {
        $user   = $this->getUser();
        $estado = $r->attributes->get(WmeDeviceAuthenticator::ATTR_ESTADO);

        // O header X-API-TOKEN é sempre conferido: sessão web sozinha não basta.
        if (!$user instanceof User || !in_array($estado, [WmeDispositivo::ESTADO_ATIVO, WmeDispositivo::ESTADO_PENDENTE], true)) {
            throw new WmeApiException(401, 'nao_autenticado', 'Token pessoal obrigatório.');
        }
        if ($user->isRejected()) {
            throw new WmeApiException(403, 'conta_bloqueada', 'Conta bloqueada.');
        }
        if ($exigirAtivo && $estado !== WmeDispositivo::ESTADO_ATIVO) {
            throw new WmeApiException(403, 'email_nao_confirmado', 'Confirme seu e-mail para continuar.');
        }

        $device = $r->attributes->get(WmeDeviceAuthenticator::ATTR_DISPOSITIVO);

        return [$user, $device instanceof WmeDispositivo ? $device : null, (string) $estado];
    }

    private function run(callable $action): JsonResponse
    {
        try {
            $result   = $action();
            $response = $result instanceof JsonResponse ? $result : $this->json($result);
        } catch (WmeApiException $e) {
            $response = $this->json(
                array_merge(['codigo' => $e->getCodigo(), 'message' => $e->getMessage()], $e->getExtra()),
                $e->getStatusCode()
            );
        } catch (HttpException $e) {
            $response = $this->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            $this->logger->error('WME API: {class}', ['class' => $e::class]);
            $response = $this->json(
                ['codigo' => 'erro_interno', 'message' => 'Falha interna. Consulte o envio antes de repetir.'],
                500
            );
        }

        return $response->setPrivate()->setMaxAge(0);
    }

    private function str(array $data, string $key): string
    {
        $v = $data[$key] ?? '';

        return is_scalar($v) ? trim((string) $v) : '';
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $dot  = strrpos($domain, '.');
        $host = $dot === false ? $domain : substr($domain, 0, $dot);
        $tld  = $dot === false ? '' : substr($domain, $dot);

        return mb_substr($local, 0, 1) . '***@' . mb_substr($host, 0, 1) . '***' . $tld;
    }

    private function enviarVerificacao(WmeDispositivo $device, string $verificador): bool
    {
        try {
            $this->bus->dispatch(new EnviarEmailWmeVerificacao((int) $device->getId(), $verificador));

            return true;
        } catch (\Throwable) {
            $this->logger->error('WME: e-mail de verificação não enviado (dispositivo {id})', ['id' => $device->getId()]);

            return false;
        }
    }

    // ── Conexão do dispositivo ──────────────────────────────────────────────

    #[Route('/registrar', name: 'registrar', methods: ['POST'])]
    public function registrar(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            $token = trim((string) $r->headers->get('X-API-TOKEN'));
            if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
                throw new WmeApiException(400, 'token_invalido', 'Token ausente ou em formato inválido.');
            }

            $raw = $r->getContent();
            if (strlen($raw) > 4096) {
                throw new WmeApiException(400, 'payload_invalido', 'Payload maior que 4 KB.');
            }
            try {
                $p = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new WmeApiException(400, 'payload_invalido', 'JSON inválido.');
            }
            if (!is_array($p)) {
                throw new WmeApiException(400, 'payload_invalido', 'Payload inválido.');
            }

            $email   = mb_strtolower($this->str($p, 'email'));
            $apelido = $this->str($p, 'wazeNickname');
            $nome    = $this->str($p, 'nome');

            $errors = [];
            if ($email === '' || strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = ['E-mail inválido.'];
            }
            if ($apelido === '' || mb_strlen($apelido) > 60 || preg_match('/[\x00-\x1F\x7F<>]/u', $apelido) === 1) {
                $errors['wazeNickname'] = ['Apelido do WME inválido.'];
            }
            if ($nome !== '' && (mb_strlen($nome) > 120 || preg_match('/[\x00-\x1F\x7F<>]/u', $nome) === 1)) {
                $errors['nome'] = ['Nome inválido.'];
            }
            if ($errors) {
                throw new WmeApiException(422, 'validacao', 'Revise os campos.', ['errors' => $errors]);
            }
            if ($nome === '') {
                $nome = $apelido;
            }

            $hash   = hash('sha256', $token);
            $db     = $this->em->getConnection();
            $existe = $this->em->getRepository(WmeDispositivo::class)->findOneBy(['tokenHash' => $hash]);
            if ($existe instanceof WmeDispositivo) {
                throw new WmeApiException(409, 'token_ja_registrado', 'Este token já foi registrado.', ['estado' => $existe->getEstado()]);
            }

            $ipHash = hash_hmac('sha256', (string) ($r->getClientIp() ?? ''), (string) $this->getParameter('kernel.secret'));
            $desde  = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');

            if ((int) $db->fetchOne('SELECT COUNT(*) FROM wme_dispositivo WHERE ip_hash = ? AND criado_em > ?', [$ipHash, $desde]) >= self::MAX_CADASTROS_POR_IP_H) {
                throw new WmeApiException(429, 'muitas_tentativas', 'Muitas tentativas. Aguarde um pouco.');
            }

            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user instanceof User) {
                // Conta existente: nada é alterado; só o dono do e-mail consegue ativar o token.
                $pendentes = (int) $db->fetchOne(
                    'SELECT COUNT(*) FROM wme_dispositivo WHERE user_id = ? AND estado = ? AND criado_em > ?',
                    [$user->getId(), WmeDispositivo::ESTADO_PENDENTE, $desde]
                );
                if ($pendentes >= self::MAX_PENDENTES_POR_USER) {
                    throw new WmeApiException(429, 'muitas_tentativas', 'Muitas tentativas. Aguarde um pouco.');
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
            $device->setApelidoDeclarado($apelido)
                ->setScriptVersion(substr($this->str($p, 'scriptVersion'), 0, 20) ?: null)
                ->setIpHash($ipHash);
            $verificador = bin2hex(random_bytes(24));
            $device->definirVerificacao(hash('sha256', $verificador), new \DateTimeImmutable(self::VERIFICACAO_VALIDADE));
            $this->em->persist($device);

            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                throw new WmeApiException(409, 'tente_novamente', 'Conflito ao registrar. Tente novamente.');
            }

            $enviado = $this->enviarVerificacao($device, $verificador);

            // Mesma resposta para conta nova e existente (não revela se o e-mail já tem conta).
            return new JsonResponse([
                'estado'        => WmeDispositivo::ESTADO_PENDENTE,
                'emailMascarado' => $this->maskEmail($email),
                'emailEnviado'  => $enviado,
                'expiraEm'      => $device->getVerificacaoExpiraEm()?->format(DATE_ATOM),
                'reenvioEm'     => (new \DateTimeImmutable('+' . self::REENVIO_SEGUNDOS . ' seconds'))->format(DATE_ATOM),
            ], 202);
        });
    }

    #[Route('/verificacao/reenviar', name: 'reenviar', methods: ['POST'])]
    public function reenviar(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            [$u, $device, $estado] = $this->auth($r, false);
            if ($estado === WmeDispositivo::ESTADO_ATIVO || $device === null) {
                return ['estado' => WmeDispositivo::ESTADO_ATIVO];
            }

            $ultimo = $device->getVerificacaoEnviadaEm();
            if ($ultimo !== null) {
                $espera = self::REENVIO_SEGUNDOS - (time() - $ultimo->getTimestamp());
                if ($espera > 0) {
                    throw new WmeApiException(429, 'aguarde', 'Aguarde para reenviar o e-mail.', ['aguardarSegundos' => $espera]);
                }
            }

            $verificador = bin2hex(random_bytes(24));
            $device->definirVerificacao(hash('sha256', $verificador), new \DateTimeImmutable(self::VERIFICACAO_VALIDADE));
            $this->em->flush();

            return [
                'estado'         => WmeDispositivo::ESTADO_PENDENTE,
                'emailMascarado' => $this->maskEmail($u->getEmail()),
                'emailEnviado'   => $this->enviarVerificacao($device, $verificador),
                'expiraEm'       => $device->getVerificacaoExpiraEm()?->format(DATE_ATOM),
                'reenvioEm'      => (new \DateTimeImmutable('+' . self::REENVIO_SEGUNDOS . ' seconds'))->format(DATE_ATOM),
            ];
        });
    }

    /**
     * Link do e-mail. GET mostra um botão (evita que scanners de e-mail consumam o link);
     * POST confirma e ativa o dispositivo.
     */
    #[Route(
        '/confirmar/{id}/{verificador}',
        name: 'confirmar',
        methods: ['GET', 'POST'],
        requirements: ['id' => '\d+', 'verificador' => '[a-f0-9]{48}']
    )]
    public function confirmar(int $id, string $verificador, Request $r): Response
    {
        $device = $this->em->find(WmeDispositivo::class, $id);
        $hashOk = $device instanceof WmeDispositivo
            && $device->isPendente()
            && $device->getVerificacaoHash() !== null
            && hash_equals($device->getVerificacaoHash(), hash('sha256', $verificador));

        if (!$hashOk) {
            return $this->pagina('Link inválido ou já usado', 'Peça um novo e-mail de confirmação no script do WME.', 400);
        }
        /** @var WmeDispositivo $device */
        if ($device->getVerificacaoExpiraEm() === null || $device->getVerificacaoExpiraEm() < new \DateTimeImmutable()) {
            return $this->pagina('Link expirado', 'Volte ao WME e peça um novo e-mail de confirmação.', 410);
        }
        if ($device->getUser()->isRejected()) {
            return $this->pagina('Conta indisponível', 'Esta conta não pode usar o script. Fale com a administração.', 403);
        }

        if ($r->isMethod('POST')) {
            $device->ativar();
            $this->em->flush();

            return $this->pagina('E-mail confirmado', 'Pronto! Volte ao WME: o script detecta a confirmação sozinho.', 200);
        }

        return $this->pagina(
            'Confirmar e-mail',
            'Confirme que ' . $this->maskEmail($device->getUser()->getEmail()) . ' é o seu e-mail para liberar o script de solicitações do WME.',
            200,
            true
        );
    }

    private function pagina(string $titulo, string $texto, int $status, bool $comBotao = false): Response
    {
        $t = htmlspecialchars($titulo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $x = htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $botao = $comBotao
            ? '<form method="post" action=""><button type="submit">Confirmar e-mail</button></form>'
            : '';

        $html = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $t . ' — ToolboxWaze</title>'
            . '<style>body{font:16px/1.5 system-ui,sans-serif;background:#f4f8f5;color:#16342b;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}'
            . 'main{background:#fff;border:1px solid #dce9e1;border-radius:14px;padding:28px;max-width:440px;box-shadow:0 8px 30px #0001}'
            . 'h1{font-size:1.3rem;margin:0 0 10px}p{margin:0 0 18px;color:#40594d}'
            . 'button{background:#176d4c;color:#fff;border:0;border-radius:9px;padding:12px 22px;font:inherit;font-weight:700;cursor:pointer}</style></head>'
            . '<body><main><h1>' . $t . '</h1><p>' . $x . '</p>' . $botao . '</main></body></html>';

        $response = new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/dispositivo/revogar', name: 'revogar', methods: ['POST'])]
    public function revogar(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            [, $device] = $this->auth($r, false);
            if ($device !== null) {
                $device->revogar();
                $this->em->flush();
            }

            return ['estado' => WmeDispositivo::ESTADO_REVOGADO];
        });
    }

    // ── Dados do usuário e formulários ──────────────────────────────────────

    #[Route('/bootstrap', name: 'bootstrap', methods: ['GET'])]
    public function bootstrap(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            [$u, $device, $estado] = $this->auth($r, false);

            if ($estado === WmeDispositivo::ESTADO_PENDENTE) {
                $ultimo = $device?->getVerificacaoEnviadaEm();
                $reenvio = $ultimo?->modify('+' . self::REENVIO_SEGUNDOS . ' seconds');

                return [
                    'estado'      => WmeDispositivo::ESTADO_PENDENTE,
                    'usuario'     => ['emailMascarado' => $this->maskEmail($u->getEmail()), 'wazeNickname' => $device?->getApelidoDeclarado()],
                    'expiraEm'    => $device?->getVerificacaoExpiraEm()?->format(DATE_ATOM),
                    'reenvioEm'   => $reenvio?->format(DATE_ATOM),
                    'formularios' => [],
                ];
            }

            return [
                'estado'      => WmeDispositivo::ESTADO_ATIVO,
                'usuario'     => ['id' => $u->getId(), 'nome' => $u->getName(), 'wazeNickname' => $u->getWazeNickname(), 'aprovado' => $u->isApproved()],
                'formularios' => $this->forms->available($u),
            ];
        });
    }

    #[Route('/formularios/{slug}', name: 'formulario', methods: ['GET'])]
    public function formulario(string $slug, Request $r): JsonResponse
    {
        return $this->run(function () use ($slug, $r) {
            [$u] = $this->auth($r);

            return $this->forms->schema($this->forms->get($slug, $u));
        });
    }

    #[Route('/solicitacoes', name: 'minhas', methods: ['GET'])]
    public function minhas(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            [$u] = $this->auth($r);
            $rows = $this->em->getRepository(Solicitacao::class)
                ->findBy(['solicitanteEmail' => $u->getEmail()], ['criadaEm' => 'DESC'], 20);

            return ['solicitacoes' => array_map(fn (Solicitacao $s) => [
                'id'          => $s->getId(),
                'formulario'  => $s->getFormulario()?->getNome() ?? $s->getTipoLabel(),
                'status'      => $s->getStatus(),
                'statusLabel' => $s->getStatusLabel(),
                'criadaEm'    => $s->getCriadaEm()->format(DATE_ATOM),
            ], $rows)];
        });
    }

    // ── Envio idempotente ───────────────────────────────────────────────────

    private function key(User $u, string $uuid): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $uuid)) {
            throw new WmeApiException(400, 'chave_invalida', 'clientRequestId inválido.');
        }

        return $u->getId() . ':' . strtolower($uuid);
    }

    private function lock(User $u): void
    {
        $this->em->getConnection()->executeQuery('SELECT id FROM `user` WHERE id = ? FOR UPDATE', [$u->getId()])->fetchOne();
    }

    private function outcome(array $row): array
    {
        if ($row['estado'] !== 'concluido') {
            return ['estado' => $row['estado']];
        }

        return [
            'estado'      => 'concluido',
            'solicitacao' => [
                'id'  => (int) $row['solicitacao_id'],
                'url' => $this->generateUrl('solicitacao_detalhe', ['id' => $row['solicitacao_id']]),
            ],
        ];
    }

    #[Route('/envios/{clientRequestId}', name: 'envio', methods: ['GET'])]
    public function envio(string $clientRequestId, Request $r): JsonResponse
    {
        return $this->run(function () use ($clientRequestId, $r) {
            [$u] = $this->auth($r);
            $key = $this->key($u, $clientRequestId);
            $db  = $this->em->getConnection();
            $db->beginTransaction();
            try {
                $this->lock($u);
                $row = $db->fetchAssociative('SELECT * FROM wme_envio WHERE id = ?', [$key]);
                if (!$row) {
                    $db->insert('wme_envio', [
                        'id'             => $key,
                        'user_id'        => $u->getId(),
                        'hash'           => str_repeat('0', 64),
                        'estado'         => 'nao_criado',
                        'solicitacao_id' => null,
                        'criado_em'      => date('Y-m-d H:i:s'),
                    ]);
                    $row = ['estado' => 'nao_criado'];
                }
                $db->commit();

                return $this->outcome($row);
            } catch (\Throwable $e) {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
                throw $e;
            }
        });
    }

    #[Route('/solicitacoes', name: 'criar', methods: ['POST'])]
    public function criar(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            [$u] = $this->auth($r);

            $raw = $r->request->get('payload');
            if (!is_string($raw) || strlen($raw) > 262144) {
                throw new WmeApiException(400, 'payload_invalido', 'Payload ausente ou maior que 256 KB.');
            }
            try {
                $p = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new WmeApiException(400, 'payload_invalido', 'JSON inválido.');
            }
            if (!is_array($p) || !is_string($p['formulario'] ?? null) || !is_string($p['clientRequestId'] ?? null) || !is_array($p['respostas'] ?? null)) {
                throw new WmeApiException(400, 'payload_invalido', 'Payload inválido.');
            }

            $key    = $this->key($u, $p['clientRequestId']);
            $form   = $this->forms->get($p['formulario'], $u);
            $schema = $this->forms->schema($form);
            if (($p['versaoFormulario'] ?? '') !== $schema['versao']) {
                throw new WmeApiException(422, 'formulario_desatualizado', 'Formulário atualizado. Recarregue sua definição.');
            }

            [$clean, $files, $errors] = $this->forms->validate($schema, $p['respostas'], $r->files->all('anexos'));
            if ($errors) {
                throw new WmeApiException(422, 'validacao', 'Revise os campos.', ['errors' => $errors]);
            }

            $digest = [];
            foreach ($files as $k => $list) {
                foreach ($list as $f) {
                    $digest[$k][] = hash_file('sha256', $f->getPathname());
                }
            }
            $hash = hash('sha256', json_encode([$p, $digest], JSON_THROW_ON_ERROR));

            $db = $this->em->getConnection();
            $db->beginTransaction();
            $moved = [];
            $responsaveis = [];
            try {
                $this->lock($u);
                $row = $db->fetchAssociative('SELECT * FROM wme_envio WHERE id = ?', [$key]);
                if ($row) {
                    if ($row['estado'] === 'nao_criado' || !hash_equals($row['hash'], $hash)) {
                        throw new WmeApiException(409, 'chave_reutilizada', 'Chave encerrada ou reutilizada com outro conteúdo.');
                    }
                    $db->commit();

                    return $this->outcome($row);
                }

                $recentes = (int) $db->fetchOne(
                    'SELECT COUNT(*) FROM wme_envio WHERE user_id = ? AND estado = ? AND criado_em > ?',
                    [$u->getId(), 'concluido', date('Y-m-d H:i:s', time() - 60)]
                );
                if ($recentes >= 10) {
                    throw new WmeApiException(429, 'limite_minuto', 'Limite de dez envios por minuto.');
                }

                $this->forms->get($p['formulario'], $u);
                $limit = (int) (($form->getConfiguracoes() ?? [])['limite_envios'] ?? 1);
                if ($limit > 0 && $this->em->getRepository(Solicitacao::class)->count(['formulario' => $form, 'solicitanteEmail' => $u->getEmail()]) >= $limit) {
                    throw new WmeApiException(422, 'limite_formulario', 'Limite de envios deste formulário atingido.');
                }

                $saved = [];
                $dir   = $this->getParameter('kernel.project_dir') . '/var/wme_uploads';
                foreach ($files as $field => $list) {
                    foreach ($list as $file) {
                        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                            throw new \RuntimeException('Storage indisponivel.');
                        }
                        $mime = $file->getMimeType();
                        $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
                        $name = bin2hex(random_bytes(20)) . '.' . $ext;
                        $size = $file->getSize();
                        $file->move($dir, $name);
                        $moved[] = $dir . '/' . $name;
                        $saved[] = ['campo' => $field, 'nome' => $name, 'mime' => $mime, 'bytes' => $size];
                    }
                }

                $sol = new Solicitacao();
                $sol->setFormulario($form)
                    ->setTipo($form->getSlug())
                    ->setStatus(Solicitacao::STATUS_PENDENTE)
                    ->setSolicitanteNome($u->getName() ?: $u->getEmail())
                    ->setSolicitanteUsuario($u->getWazeNickname() ?: $u->getEmail())
                    ->setSolicitanteEmail($u->getEmail())
                    ->setDadosDinamicos($clean)
                    ->setDados(['_wme' => [
                        'contexto'        => $p['contexto'] ?? null,
                        'anexos'          => $saved,
                        'clientRequestId' => $p['clientRequestId'],
                    ]]);

                $config = $this->configs->findByTipo($form->getSlug());
                if ($config) {
                    foreach ($config->getResponsaveisDefault() as $responsavel) {
                        $sol->addResponsavel($responsavel);
                        $responsaveis[] = $responsavel;
                    }
                }

                $hist = new SolicitacaoHistorico();
                $hist->setSolicitacao($sol);
                $hist->setStatusAnterior(null);
                $hist->setStatusNovo(Solicitacao::STATUS_PENDENTE);
                $hist->setAutor($u);
                $hist->setNota('Solicitação criada via API WME.');
                $sol->addHistorico($hist);

                $this->em->persist($sol);
                $this->em->persist($hist);
                $this->em->flush();

                foreach ($responsaveis as $responsavel) {
                    $n = new Notificacao();
                    $n->setUsuario($responsavel);
                    $n->setSolicitacao($sol);
                    $n->setTipo(Notificacao::TIPO_NOVA_SOLICITACAO);
                    $n->setMensagem('Nova solicitação WME #' . $sol->getId());
                    $this->em->persist($n);
                }
                $this->em->flush();

                $db->insert('wme_envio', [
                    'id'             => $key,
                    'user_id'        => $u->getId(),
                    'hash'           => $hash,
                    'estado'         => 'concluido',
                    'solicitacao_id' => $sol->getId(),
                    'criado_em'      => date('Y-m-d H:i:s'),
                ]);
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
                foreach ($moved as $path) {
                    @unlink($path);
                }
                $this->em->clear();
                throw $e;
            }

            try {
                $this->bus->dispatch(new EnviarEmailSolicitacao('confirmacao', $sol->getId()));
                foreach ($responsaveis as $responsavel) {
                    $this->bus->dispatch(new EnviarEmailSolicitacao('responsavel', $sol->getId(), $responsavel->getId()));
                }
            } catch (\Throwable) {
                $this->logger->error('WME: e-mail não despachado para solicitação {id}', ['id' => $sol->getId()]);
            }

            return $this->outcome(['estado' => 'concluido', 'solicitacao_id' => $sol->getId()]);
        });
    }

    #[Route(
        '/solicitacoes/{id}/anexos/{nome}',
        name: 'anexo',
        methods: ['GET'],
        requirements: ['id' => '\d+', 'nome' => '[a-f0-9]{40}\.(jpg|png|webp)']
    )]
    public function anexo(int $id, string $nome, Request $r): Response
    {
        try {
            [$u] = $this->auth($r);
        } catch (WmeApiException $e) {
            return $this->json(['codigo' => $e->getCodigo(), 'message' => $e->getMessage()], $e->getStatusCode());
        }

        $sol = $this->em->find(Solicitacao::class, $id);
        if (!$sol) {
            throw new HttpException(404);
        }

        // Dono da solicitação, responsáveis e admins.
        $dono = strcasecmp($sol->getSolicitanteEmail(), $u->getEmail()) === 0;
        if (!$dono && !$this->isGranted('SOLICITACAO_VER', $sol)) {
            throw new HttpException(403);
        }

        $meta = null;
        foreach (($sol->getDados()['_wme']['anexos'] ?? []) as $a) {
            if (($a['nome'] ?? null) === $nome) {
                $meta = $a;
            }
        }
        if (!$meta) {
            throw new HttpException(404);
        }

        $response = new BinaryFileResponse($this->getParameter('kernel.project_dir') . '/var/wme_uploads/' . $nome);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $nome);
        $response->headers->set('Content-Type', $meta['mime']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
