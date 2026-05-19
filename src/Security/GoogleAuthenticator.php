<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly EntityManagerInterface $em,
        private readonly RouterInterface $router,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_google_callback';
    }

    public function authenticate(Request $request): Passport
    {
        $client      = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $repo = $this->em->getRepository(User::class);

                $user = $repo->findOneBy(['googleId' => $googleUser->getId()]);

                if (!$user) {
                    $user = $repo->findByEmail($googleUser->getEmail());
                }

                if ($user) {
                    if (!$user->getGoogleId()) {
                        $user->setGoogleId($googleUser->getId());
                        $user->setAvatarUrl($googleUser->getAvatar());
                    }
                } else {
                    $user = new User();
                    $user->setEmail($googleUser->getEmail());
                    $user->setName($googleUser->getName() ?? $googleUser->getEmail());
                    $user->setGoogleId($googleUser->getId());
                    $user->setAvatarUrl($googleUser->getAvatar());
                    $user->setStatus(User::STATUS_PENDING);
                    $this->em->persist($user);
                }

                if ($user->isPending()) {
                    $this->em->flush();
                    throw new CustomUserMessageAuthenticationException('google_pending:' . $user->getEmail());
                }
                if ($user->isRejected()) {
                    // Mensagem genérica — não expõe detalhes internos
                    throw new CustomUserMessageAuthenticationException(
                        'Sua conta não está autorizada. Entre em contato com o administrador.'
                    );
                }

                $user->setLastLoginAt(new \DateTimeImmutable());
                $this->em->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $msg = $exception->getMessageKey();

        if (str_starts_with($msg, 'google_pending:')) {
            $request->getSession()->getFlashBag()->add(
                'warning',
                'Cadastro recebido! Aguarde a aprovação do administrador.'
            );
        } else {
            // Usa getMessageKey() (string traduzível) em vez de getMessage() (técnico)
            $request->getSession()->getFlashBag()->add(
                'danger',
                $exception->getMessageKey()
            );
        }

        return new RedirectResponse($this->router->generate('app_login'));
    }
}
