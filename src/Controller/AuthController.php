<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Message\EnviarEmailConta;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;

class AuthController extends AbstractController
{
    public function __construct(
        #[Lazy] private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {
    }

    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->redirectToRoute('app_login');
    }

    // ──────────────────────── LOGIN ────────────────────────

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method should not be reached.');
    }

    // ──────────────────────── REGISTRO ────────────────────────

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $req,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        MessageBusInterface $bus,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $errors = [];
        $formValues = [
            'name' => '',
            'email' => '',
            'waze_nickname' => '',
            'requested_ufs' => [],
        ];

        if ($req->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register_request', $req->request->get('_csrf_token'))) {
                $errors[] = 'Token CSRF inválido.';
            }

            // Sanitiza e normaliza nome
            $name = trim($req->request->getString('name'));
            $name = strip_tags($name);
            $name = mb_substr($name, 0, 120);

            // Sanitiza e normaliza e-mail
            $email = trim($req->request->getString('email'));
            $email = filter_var($email, FILTER_SANITIZE_EMAIL);
            $email = strtolower($email); // evita duplicatas com diferença de caixa

            $password     = $req->request->getString('password');
            $confirm      = $req->request->getString('password2'); // corrigido para 'password2'

            // Sanitiza nickname
            $wazeNickname = $req->request->getString('waze_nickname');
            $wazeNickname = is_string($wazeNickname) ? $wazeNickname : '';
            $wazeNickname = preg_replace('/[^A-Za-z0-9_.-]/', '', $wazeNickname);
            $wazeNickname = mb_substr($wazeNickname, 0, 50);

            // UF selecionadas
            $rawUfs       = $req->request->all('requested_ufs') ?? [];
            $requestedUfs = array_values(array_filter(
                array_map('strtoupper', (array) $rawUfs),
                static fn(string $uf): bool => in_array($uf, User::ALL_UFS, true)
            ));

            // Popula valores para reexibição (seguro)
            $formValues = [
                'name' => $name,
                'email' => $email,
                'waze_nickname' => $wazeNickname,
                'requested_ufs' => $requestedUfs,
            ];

            // Validações (executa apenas se CSRF passou)
            if (empty($errors)) {
                if (!$name) {
                    $errors[] = 'Nome obrigatório.';
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'E-mail inválido.';
                }
                if ($wazeNickname === '' || mb_strlen($wazeNickname) < 3) {
                    $errors[] = 'Nickname no Waze é obrigatório e deve ter pelo menos 3 caracteres.';
                }
                if (strlen($password) < 8) {
                    $errors[] = 'Senha deve ter pelo menos 8 caracteres.';
                }
                if ($password !== $confirm) {
                    $errors[] = 'As senhas não conferem.';
                }
                if ($userRepo->findByEmail($email)) {
                    $errors[] = 'Este e-mail já está cadastrado.';
                }
            }

            // Valida nickname no backend (chamada à API do Waze)
            if (empty($errors) && $wazeNickname !== '') {
                if (!$this->validateWazeNickname($wazeNickname)) {
                    $errors[] = 'Nickname no Waze inválido ou inexistente.';
                }
            }

            // Se tudo ok, persiste
            if (empty($errors)) {
                $user = new User();
                $user->setName($name);
                $user->setEmail($email);
                $user->setPassword($hasher->hashPassword($user, $password));
                $user->setWazeNickname($wazeNickname);
                if (!empty($requestedUfs)) {
                    $user->setAllowedUfs($requestedUfs);
                }
                $user->setStatus(User::STATUS_PENDING);

                $em->persist($user);
                $em->flush();

                $bus->dispatch(new EnviarEmailConta('conta_criada', $user->getId()));

                foreach ($userRepo->findAdmins() as $admin) {
                    $bus->dispatch(new EnviarEmailConta('solicitacao_admin', $user->getId(), $admin->getId()));
                }

                $this->addFlash('success',
                    'Cadastro realizado! Aguarde a aprovação do administrador para acessar o sistema.');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('auth/register.html.twig', [
            'errors' => $errors,
            'values' => $formValues, // array seguro, sem senha nem token
        ]);
    }

    // ──────────────────────── GOOGLE OAUTH ────────────────────────

    #[Route('/connect/google', name: 'app_google_connect')]
    public function googleConnect(ClientRegistry $registry): Response
    {
        return $registry->getClient('google')->redirect(['email', 'profile']);
    }

    #[Route('/connect/google/callback', name: 'app_google_callback')]
    public function googleCallback(): Response
    {
        throw new \LogicException('This method should not be reached.');
    }

    // ──────────────────────── RECUPERAÇÃO DE SENHA ────────────────────────

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function forgotPassword(
        Request $req,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator,
    ): Response {
        $sent = false;

        if ($req->isMethod('POST')) {
            // CSRF
            if (!$this->isCsrfTokenValid('forgot_password', $req->request->get('_csrf_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_forgot_password');
            }

            $emailAddr = trim($req->request->getString('email'));
            $emailAddr = filter_var($emailAddr, FILTER_SANITIZE_EMAIL);
            $emailAddr = strtolower($emailAddr);

            if (filter_var($emailAddr, FILTER_VALIDATE_EMAIL)) {
                $user = $userRepo->findByEmail($emailAddr);
                if ($user) {
                    $token = bin2hex(random_bytes(32));
                    $user->setResetToken($token);
                    $user->setResetTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
                    $em->flush();

                    $link = $urlGenerator->generate(
                        'app_reset_password',
                        ['token' => $token],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    );

                    $email = (new Email())
                        ->from($this->getParameter('app.mail_from'))
                        ->to($user->getEmail())
                        ->subject('[ToolboxWaze] Recuperação de senha')
                        ->html($this->renderView('emails/reset_password.html.twig', [
                            'user' => $user,
                            'link' => $link,
                        ]));

                    try {
                        $mailer->send($email);
                    } catch (\Throwable $e) {
                        $this->logger->error('Falha ao enviar e-mail de recuperação: ' . $e->getMessage());
                    }
                }
            }

            $sent = true;
        }

        return $this->render('auth/forgot_password.html.twig', ['sent' => $sent]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password')]
    public function resetPassword(
        string $token,
        Request $req,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        // Valida formato do token (hex 64)
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->addFlash('danger', 'Link inválido.');
            return $this->redirectToRoute('app_forgot_password');
        }

        $user = $userRepo->findOneBy(['resetToken' => $token]);

        if (!$user || $user->getResetTokenExpiresAt() < new \DateTimeImmutable()) {
            $this->addFlash('danger', 'Link inválido ou expirado. Solicite um novo.');
            return $this->redirectToRoute('app_forgot_password');
        }

        $errors = [];

        if ($req->isMethod('POST')) {
            // CSRF
            if (!$this->isCsrfTokenValid('reset_password', $req->request->get('_csrf_token'))) {
                $errors[] = 'Token CSRF inválido.';
            }

            $password = $req->request->getString('password');
            $confirm  = $req->request->getString('confirm');

            if (strlen($password) < 8) {
                $errors[] = 'Senha deve ter pelo menos 8 caracteres.';
            }
            if ($password !== $confirm) {
                $errors[] = 'As senhas não conferem.';
            }

            if (empty($errors)) {
                $user->setPassword($hasher->hashPassword($user, $password));
                $user->setResetToken(null);
                $user->setResetTokenExpiresAt(null);
                $em->flush();

                $this->addFlash('success', 'Senha redefinida com sucesso! Faça login.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('auth/reset_password.html.twig', [
            'token'  => $token,
            'errors' => $errors,
        ]);
    }

    // ──────────────────────── MÉTODOS PRIVADOS ────────────────────────

    /**
     * Valida se o nickname existe na plataforma Waze (via API do fórum).
     */
    private function validateWazeNickname(string $nickname): bool
    {
        try {
            $response = $this->httpClient->request('GET', 'https://www.waze.com/discuss/user_actions.json', [
                'query' => [
                    'offset' => 0,
                    'username' => $nickname,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'ToolboxWaze/1.0',
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 404) {
                return false;
            }
            if ($statusCode !== 200) {
                $this->logger->warning('Falha ao validar nickname Waze: status ' . $statusCode);
                return false;
            }

            $payload = $response->toArray(false);
            return is_array($payload) && array_key_exists('user_actions', $payload);
        } catch (\Throwable $e) {
            $this->logger->error('Erro ao validar nickname Waze: ' . $e->getMessage());
            return false;
        }
    }
}