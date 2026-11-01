<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DebugSessionController extends AbstractController
{
    #[Route('/debug/session', name: 'debug_session')]
    public function __invoke(Request $request): Response
    {
        if ($this->getParameter('kernel.environment') !== 'dev') {
            throw $this->createNotFoundException();
        }

        $session = $request->getSession();
        $postedToken = $request->request->getString('_csrf_token');
        $valid = $postedToken !== ''
            ? $this->isCsrfTokenValid('authenticate', $postedToken)
            : null;

        return $this->render('debug/session.html.twig', [
            'sessionId' => $session->getId(),
            'sessionStatus' => session_status(),
            'csrfToken' => $this->container->get('security.csrf.token_manager')->getToken('authenticate')->getValue(),
            'postedToken' => $postedToken,
            'valid' => $valid,
            'cookies' => $request->cookies->all(),
        ]);
    }
}
