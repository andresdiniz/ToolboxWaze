<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface             $mailer,
        private readonly UserRepository              $userRepo,
        private readonly LoggerInterface             $logger,
        private readonly string                      $mailFrom,
    ) {}

    #[Route('/register', name: 'app_register')]
    public function register(Request $request): Response
    {
        $errors = [];
        $values = [];

        if ($request->isMethod('POST')) {
            $values = [
                'name'          => trim($request->request->get('name', '')),
                'email'         => trim($request->request->get('email', '')),
                'waze_nickname' => trim($request->request->get('waze_nickname', '')),
                'password'      => $request->request->get('password', ''),
                'password2'     => $request->request->get('password2', ''),
            ];

            if (empty($values['name']))          $errors[] = 'Nome é obrigatório.';
            if (empty($values['email']))         $errors[] = 'E-mail é obrigatório.';
            if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail inválido.';
            if (empty($values['waze_nickname'])) $errors[] = 'Nickname do Waze é obrigatório.';
            if (strlen($values['password']) < 8) $errors[] = 'Senha deve ter ao menos 8 caracteres.';
            if ($values['password'] !== $values['password2']) $errors[] = 'As senhas não coincidem.';

            if (empty($errors) && $this->userRepo->findOneBy(['email' => $values['email']])) {
                $errors[] = 'Este e-mail já está cadastrado.';
            }

            if (empty($errors)) {
                $user = new User();
                $user->setName($values['name'])
                     ->setEmail($values['email'])
                     ->setWazeNickname($values['waze_nickname'])
                     ->setPassword($this->hasher->hashPassword($user, $values['password']))
                     ->setStatus(User::STATUS_PENDING)
                     ->setRoles([]);

                $this->em->persist($user);
                $this->em->flush();

                // Notifica todos os admins sobre a nova solicitação
                $this->notifyAdmins($user);

                return $this->render('auth/register.html.twig', ['sent' => true]);
            }
        }

        return $this->render('auth/register.html.twig', [
            'errors' => $errors,
            'values' => $values,
            'sent'   => false,
        ]);
    }

    // -------------------------------------------------------------------------

    private function notifyAdmins(User $newUser): void
    {
        $admins = $this->userRepo->findAdmins();

        if (empty($admins)) {
            $this->logger->warning(
                '[Registration] Nenhum admin encontrado para notificar. Usuário: {email}',
                ['email' => $newUser->getEmail()]
            );
            return;
        }

        foreach ($admins as $admin) {
            try {
                $fromAddress = new Address($this->mailFrom, 'ToolboxWaze');

                $email = (new Email())
                    ->from($fromAddress)
                    ->to(new Address($admin->getEmail()))
                    ->subject('[ToolboxWaze] Nova solicitação de acesso — ' . $newUser->getName())
                    ->html($this->renderView('emails/new_registration.html.twig', [
                        'user'  => $newUser,
                        'admin' => $admin,
                    ]));

                $this->mailer->send($email);

                $this->logger->info(
                    '[Registration] E-mail de notificação enviado para admin {admin} sobre usuário {user}',
                    ['admin' => $admin->getEmail(), 'user' => $newUser->getEmail()]
                );
            } catch (\Throwable $e) {
                // Não bloqueia o registro, mas loga o erro com detalhes
                $this->logger->error(
                    '[Registration] Falha ao enviar e-mail para {admin}: {error}',
                    [
                        'admin' => $admin->getEmail(),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                );
            }
        }
    }
}
