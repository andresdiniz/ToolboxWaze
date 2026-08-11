<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/stream', name: 'app_dashboard_stream')]
#[IsGranted('ROLE_USER')]
final class StreamController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(): StreamedResponse
    {
        $user = $this->getUser();
        $allowedUfs = $user instanceof User ? $user->getUfsForQuery() : null;
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        return new StreamedResponse(function () use ($allowedUfs, $isAdmin) {
            // ... lógica igual à que estava no DashboardController (sseStream)
            // Apenas movida para cá.
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}