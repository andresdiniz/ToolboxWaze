<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stub de compatibilidade — redireciona para o controller real em Admin/AdminUserController.
 * Mantido apenas para não quebrar bookmarks antigos.
 */
#[Route('/admin/users-legacy', name: 'admin_users_legacy_')]
class AdminUserLegacyController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->redirectToRoute('admin_users_index');
    }
}
