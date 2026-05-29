<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Message\EnviarEmailConta;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;

class AuthController extends AbstractController
{
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

        if ($req->isMethod('POST')) {
            $name         = trim($req->request->getString('name'));
            $email        = trim($req->request->getString('email'));
            $password     = $req->request->getString('password');
            $confirm      = $req->request->getString('confirm');
            $wazeNickname = trim($req->request->getString('waze_nickname'));

            // Estados solicitados: filtra apenas UFs válidas
            $rawUfs       = $req->request->all('requested_ufs') ?? [];
            $requestedUfs = array_values(array_filter(
                array_map('strtoupper', (array) $rawUfs),
                static fn(string $uf): bool => in_array($uf, User::ALL_UFS, true)
            ));

            if (!$name)  $errors[] = 'Nome obrigatório.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail inválido.';
            if (!$wazeNickname) $errors[] = 'Nickname no Waze obrigatório.';
            if (strlen($password) < 8) $errors[] = 'Senha deve ter pelo menos 8 caracteres.';
            if ($password !== $confirm) $errors[] = 'As senhas não conferem.';
            if ($userRepo->findByEmail($email)) $errors[] = 'Este e-mail já está cadastrado.';

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
            'values' => $req->request->all(),
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
            $emailAddr = trim($req->request->getString('email'));
            $user      = $userRepo->findByEmail($emailAddr);

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

                try { $mailer->send($email); } catch (\Throwable) {}
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
        $user = $userRepo->findOneBy(['resetToken' => $token]);

        if (!$user || $user->getResetTokenExpiresAt() < new \DateTimeImmutable()) {
            $this->addFlash('danger', 'Link inválido ou expirado. Solicite um novo.');
            return $this->redirectToRoute('app_forgot_password');
        }

        $errors = [];

        if ($req->isMethod('POST')) {
            $password = $req->request->getString('password');
            $confirm  = $req->request->getString('confirm');

            if (strlen($password) < 8) $errors[] = 'Senha deve ter pelo menos 8 caracteres.';
            if ($password !== $confirm) $errors[] = 'As senhas não conferem.';

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

    // ──────────────────────── DASHBOARD ────────────────────────
    // Redireciona para o DashboardController real (rota dashboard_index em /)

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->redirectToRoute('dashboard_index');
    }
}
