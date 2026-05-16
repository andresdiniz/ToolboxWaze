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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users', name: 'admin_users_')]
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly MailerInterface $mailer,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        return $this->render('admin/users/index.html.twig', [
            'pending'   => $this->userRepo->findBy(['status' => User::STATUS_PENDING], ['createdAt' => 'ASC']),
            'approved'  => $this->userRepo->findBy(['status' => User::STATUS_APPROVED], ['name' => 'ASC']),
            'rejected'  => $this->userRepo->findBy(['status' => User::STATUS_REJECTED], ['createdAt' => 'DESC']),
            'allPerms'  => User::ALL_PERMISSIONS,
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'])]
    public function approve(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('user_action_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_users_index');
        }

        $permissions = $request->request->all('permissions') ?? [];
        $roles = ['ROLE_USER'];
        if ($request->request->getBoolean('is_admin')) {
            $roles[] = 'ROLE_ADMIN';
        }

        $user->setStatus(User::STATUS_APPROVED)
             ->setApprovedAt(new \DateTimeImmutable())
             ->setRoles($roles)
             ->setPermissions($permissions);

        $this->em->flush();

        // Notifica o usuário aprovado
        if ($user->getEmail()) {
            $email = (new Email())
                ->from($this->getParameter('app.mail_from'))
                ->to($user->getEmail())
                ->subject('[ToolboxWaze] Seu acesso foi aprovado!')
                ->html($this->renderView('emails/user_approved.html.twig', ['user' => $user]));
            $this->mailer->send($email);
        }

        $this->addFlash('success', "Usuário {$user->getName()} aprovado com sucesso.");
        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('user_action_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_users_index');
        }

        $user->setStatus(User::STATUS_REJECTED);
        $this->em->flush();

        $this->addFlash('warning', "Usuário {$user->getName()} rejeitado.");
        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/permissions', name: 'permissions', methods: ['POST'])]
    public function updatePermissions(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('user_perms_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_users_index');
        }

        $permissions = $request->request->all('permissions') ?? [];
        $roles = ['ROLE_USER'];
        if ($request->request->getBoolean('is_admin')) {
            $roles[] = 'ROLE_ADMIN';
        }

        $user->setRoles($roles)->setPermissions($permissions);
        $this->em->flush();

        $this->addFlash('success', "Permissões de {$user->getName()} atualizadas.");
        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('user_delete_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_users_index');
        }

        $name = $user->getName();
        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', "Usuário {$name} removido.");
        return $this->redirectToRoute('admin_users_index');
    }
}
