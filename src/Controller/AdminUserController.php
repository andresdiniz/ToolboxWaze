<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users-legacy', name: 'admin_users_legacy_')]
class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepo,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return $this->redirectToRoute('admin_users_index');
    }
}
