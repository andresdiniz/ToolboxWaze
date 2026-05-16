<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface $mailer,
        private readonly UserRepository $userRepo,
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

                // Notifica todos os admins
                $admins = $this->userRepo->findAdmins();
                foreach ($admins as $admin) {
                    $adminEmail = (new Email())
                        ->from($this->getParameter('app.mail_from'))
                        ->to($admin->getEmail())
                        ->subject('[ToolboxWaze] Nova solicitação de acesso')
                        ->html($this->renderView('emails/new_registration.html.twig', [
                            'user'  => $user,
                            'admin' => $admin,
                        ]));
                    $this->mailer->send($adminEmail);
                }

                return $this->render('auth/register.html.twig', ['sent' => true]);
            }
        }

        return $this->render('auth/register.html.twig', [
            'errors' => $errors,
            'values' => $values,
            'sent'   => false,
        ]);
    }
}
