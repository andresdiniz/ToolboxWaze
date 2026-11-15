<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\WmeDispositivo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Autentica /api/wme/* pelo header X-API-TOKEN.
 *
 * 1. Token de dispositivo: o userscript gera 64 hex aleatórios; aqui buscamos sha256(token).
 *    Estado "pendente" (e-mail ainda não confirmado) autentica, mas a API só libera
 *    bootstrap/reenvio/revogação; o restante exige estado "ativo".
 * 2. Legado: token pessoal gerado no site (User.apiToken) de conta aprovada continua valendo.
 *
 * Rotas públicas (cadastro e confirmação de e-mail) não passam por aqui.
 */
final class WmeDeviceAuthenticator extends AbstractAuthenticator
{
    public const ATTR_DISPOSITIVO = '_wme_dispositivo';
    public const ATTR_ESTADO      = '_wme_estado';

    private const PUBLIC_PATHS = ['/api/wme/registrar'];
    private const PUBLIC_PREFIXES = ['/api/wme/confirmar/'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/api/wme/')) {
            return false;
        }
        if (in_array($path, self::PUBLIC_PATHS, true)) {
            return false;
        }
        foreach (self::PUBLIC_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        // Sempre autentica (mesmo sem header) para devolver 401 JSON em vez de redirecionar ao login.
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $token = trim((string) $request->headers->get('X-API-TOKEN'));

        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new CustomUserMessageAuthenticationException(
                'Token ausente ou em formato inválido.',
                ['codigo' => 'token_invalido']
            );
        }

        $device = $this->em->getRepository(WmeDispositivo::class)
            ->findOneBy(['tokenHash' => hash('sha256', $token)]);

        if ($device instanceof WmeDispositivo) {
            if ($device->isRevogado()) {
                throw new CustomUserMessageAuthenticationException(
                    'Este dispositivo foi desconectado. Gere uma nova conexão.',
                    ['codigo' => 'dispositivo_revogado']
                );
            }

            $user   = $device->getUser();
            $estado = $device->getEstado();

            $last = $device->getUltimoUsoEm();
            $now  = new \DateTimeImmutable();
            if ($last === null || $last < $now->modify('-5 minutes')) {
                $device->setUltimoUsoEm($now);
                $this->em->flush();
            }
        } else {
            // Compatibilidade: token pessoal gerado no site.
            $user = $this->em->getRepository(User::class)->findOneBy(['apiToken' => $token]);
            if (!$user instanceof User || !$user->isApproved()) {
                throw new CustomUserMessageAuthenticationException(
                    'Token desconhecido.',
                    ['codigo' => 'token_desconhecido']
                );
            }
            $device = null;
            $estado = WmeDispositivo::ESTADO_ATIVO;
        }

        $request->attributes->set(self::ATTR_DISPOSITIVO, $device);
        $request->attributes->set(self::ATTR_ESTADO, $estado);

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier()));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $data = $exception->getMessageData();

        return new JsonResponse([
            'codigo'  => is_string($data['codigo'] ?? null) ? $data['codigo'] : 'nao_autenticado',
            'message' => $exception instanceof CustomUserMessageAuthenticationException
                ? $exception->getMessage()
                : 'Não autenticado.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
