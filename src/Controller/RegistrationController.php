<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Message\EnviarEmailConta;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MessageBusInterface         $bus,
        private readonly UserRepository              $userRepo,
        private readonly LoggerInterface             $logger,
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
                'requested_ufs' => array_values(array_filter(
                    (array) $request->request->all('requested_ufs'),
                    fn($v) => in_array($v, User::ALL_UFS, true)
                )),
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

                // Salva os estados solicitados (null = nenhum selecionado, array = selecionados)
                $user->setAllowedUfs(!empty($values['requested_ufs']) ? $values['requested_ufs'] : []);

                $this->em->persist($user);
                $this->em->flush();

                // 1. Confirmação para o próprio usuário
                $this->dispatchEmail('conta_criada', $user->getId());

                // 2. Notificação para cada admin
                $admins = $this->userRepo->findAdmins();
                if (empty($admins)) {
                    $this->logger->warning('[Registration] Nenhum admin encontrado para notificar. Usuário: {email}', [
                        'email' => $user->getEmail(),
                    ]);
                } else {
                    foreach ($admins as $admin) {
                        $this->dispatchEmail('solicitacao_admin', $user->getId(), $admin->getId());
                    }
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

    // -------------------------------------------------------------------------

    private function dispatchEmail(string $tipo, int $userId, ?int $adminId = null): void
    {
        try {
            $this->bus->dispatch(new EnviarEmailConta($tipo, $userId, $adminId));
        } catch (\Throwable $e) {
            $this->logger->error('[Registration] Falha ao despachar email via Messenger', [
                'tipo'     => $tipo,
                'user_id'  => $userId,
                'admin_id' => $adminId,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
        }
    }
}
