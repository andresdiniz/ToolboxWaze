<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Solicitacao;
use App\Entity\User;
use App\Message\EnviarEmailConta;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users-legacy', name: 'admin_users_legacy_')]
class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository         $userRepo,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $emailQueueLogger,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return $this->redirectToRoute('admin_users_index');
    }
}
