<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\WmeDispositivo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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

final class WmeDeviceAuthenticator extends AbstractAuthenticator
{
    public const ATTR_DISPOSITIVO = '_wme_dispositivo';
    public const ATTR_ESTADO = '_wme_estado';

    private const PUBLIC_PATHS = ['/api/wme/registrar'];
    private const PUBLIC_PREFIXES = ['/api/wme/confirmar/'];
    private const EXPIRACAO_DIAS = 90;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
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

        try {
            $device = $this->em->getRepository(WmeDispositivo::class)
                ->findOneBy(['tokenHash' => hash('sha256', $token)]);
        } catch (\Throwable $exception) {
            $this->logger->error('WME: falha ao localizar dispositivo', [
                'erro' => $exception->getMessage(),
            ]);

            throw new CustomUserMessageAuthenticationException(
                'Não foi possível validar o dispositivo.',
                ['codigo' => 'erro_interno']
            );
        }

        if ($device instanceof WmeDispositivo) {
            if ($device->isRevogado()) {
                throw new CustomUserMessageAuthenticationException(
                    'Este dispositivo foi desconectado. Gere uma nova conexão.',
                    ['codigo' => 'dispositivo_revogado']
                );
            }

            if ($device->isExpirado()) {
                throw new CustomUserMessageAuthenticationException(
                    'Este dispositivo expirou por inatividade. Conecte novamente.',
                    ['codigo' => 'dispositivo_expirado']
                );
            }

            $user = $device->getUser();
            $estado = $device->getEstado();

            $ultimoUso = $device->getUltimoUsoEm();
            $agora = new \DateTimeImmutable();

            if ($ultimoUso === null || $ultimoUso < $agora->modify('-5 minutes')) {
                $device->setUltimoUsoEm($agora);
                $this->em->flush();
            }
        } else {
            $user = $this->em->getRepository(User::class)
                ->findOneBy(['apiToken' => $token]);

            if (!$user instanceof User || !$user->isApproved()) {
                throw new CustomUserMessageAuthenticationException(
                    'Token desconhecido.',
                    ['codigo' => 'token_desconhecido']
                );
            }

            $device = null;
            $estado = WmeDispositivo::ESTADO_ATIVO;
        }

        if (!$user instanceof User) {
            throw new CustomUserMessageAuthenticationException(
                'Dispositivo não vinculado a uma conta.',
                ['codigo' => 'token_desconhecido']
            );
        }

        $request->attributes->set(self::ATTR_DISPOSITIVO, $device);
        $request->attributes->set(self::ATTR_ESTADO, $estado);

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier())
        );
    }

    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
        string $firewallName
    ): ?Response {
        return null;
    }

    public function onAuthenticationFailure(
        Request $request,
        AuthenticationException $exception
    ): ?Response {
        $data = $exception->getMessageData();

        return new JsonResponse([
            'codigo' => is_string($data['codigo'] ?? null)
                ? $data['codigo']
                : 'nao_autenticado',
            'message' => $exception instanceof CustomUserMessageAuthenticationException
                ? $exception->getMessage()
                : 'Não autenticado.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}