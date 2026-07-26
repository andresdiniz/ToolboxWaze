<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class AppAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private readonly RouterInterface $router,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = trim($request->getPayload()->getString('email'));
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email, function (string $userIdentifier) {
                /** @var User|null $user */
                $user = $this->em->getRepository(User::class)->findOneBy(['email' => $userIdentifier]);

                if (!$user) {
                    throw new CustomUserMessageAuthenticationException('E-mail ou senha incorretos.');
                }

                if ($user->isPending()) {
                    throw new CustomUserMessageAuthenticationException('Sua conta está aguardando aprovação do administrador.');
                }

                if ($user->isRejected()) {
                    throw new CustomUserMessageAuthenticationException('Sua conta foi recusada. Entre em contato com o administrador.');
                }

                return $user;
            }),
            new PasswordCredentials($request->getPayload()->getString('password')),
            [
                new CsrfTokenBadge('authenticate', $request->getPayload()->getString('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var User $user */
        $user = $token->getUser();
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $message = 'Muitas tentativas de login. Aguarde alguns minutos antes de tentar novamente.';

            if (method_exists($exception, 'getRetryAfter')) {
                $retryAfter = $exception->getRetryAfter();

                if ($retryAfter instanceof \DateTimeInterface) {
                    $seconds = max(1, $retryAfter->getTimestamp() - time());

                    if ($seconds < 60) {
                        $message = sprintf(
                            'Muitas tentativas de login. Tente novamente em %d segundo%s.',
                            $seconds,
                            $seconds === 1 ? '' : 's'
                        );
                    } else {
                        $minutes = (int) ceil($seconds / 60);
                        $message = sprintf(
                            'Muitas tentativas de login. Tente novamente em %d minuto%s.',
                            $minutes,
                            $minutes === 1 ? '' : 's'
                        );
                    }
                }
            }

            $exception = new CustomUserMessageAuthenticationException($message);
        }

        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->getLoginUrl($request));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->router->generate(self::LOGIN_ROUTE);
    }
}