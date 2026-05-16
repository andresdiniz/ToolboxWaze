<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users', name: 'admin_user_')]
final class AdminUserController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(UserRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/users/index.html.twig', [
            'pending'  => $repo->findPending(),
            'all'      => $repo->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'])]
    public function approve(User $user, EntityManagerInterface $em, Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->isCsrfTokenValid('user_action_' . $user->getId(), $req->request->getString('_token'))
            || throw $this->createAccessDeniedException('Token CSRF inválido.');

        $user->setStatus(User::STATUS_APPROVED);
        $user->setApprovedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('success', "Usuário {$user->getName()} aprovado com sucesso!");
        return $this->redirectToRoute('admin_user_index');
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(User $user, EntityManagerInterface $em, Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->isCsrfTokenValid('user_action_' . $user->getId(), $req->request->getString('_token'))
            || throw $this->createAccessDeniedException('Token CSRF inválido.');

        $user->setStatus(User::STATUS_REJECTED);
        $em->flush();

        $this->addFlash('warning', "Usuário {$user->getName()} recusado.");
        return $this->redirectToRoute('admin_user_index');
    }

    #[Route('/{id}/toggle-admin', name: 'toggle_admin', methods: ['POST'])]
    public function toggleAdmin(User $user, EntityManagerInterface $em, Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->isCsrfTokenValid('user_action_' . $user->getId(), $req->request->getString('_token'))
            || throw $this->createAccessDeniedException('Token CSRF inválido.');

        if ($user === $this->getUser()) {
            $this->addFlash('danger', 'Você não pode alterar seu próprio papel.');
            return $this->redirectToRoute('admin_user_index');
        }

        $roles = $user->isAdmin()
            ? array_filter($user->getRoles(), fn($r) => $r !== 'ROLE_ADMIN')
            : [...$user->getRoles(), 'ROLE_ADMIN'];

        $user->setRoles(array_values(array_unique($roles)));
        $em->flush();

        $this->addFlash('success', 'Papel do usuário atualizado.');
        return $this->redirectToRoute('admin_user_index');
    }
}
