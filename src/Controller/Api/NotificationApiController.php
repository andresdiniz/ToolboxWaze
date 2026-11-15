<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\NotificationPreference;
use App\Repository\NotificationPreferenceRepository;
use App\Service\Notification\EmailNotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/notificacoes')]
final class NotificationApiController extends AbstractController
{
    private const MAX_TITLE = 180;
    private const MAX_BODY = 5000;
    private const MAX_LINK = 500;

    public function __construct(
        private readonly NotificationPreferenceRepository $preferences,
        private readonly EmailNotificationService $notifications,
    ) {
    }

    #[Route('/preferencias', name: 'api_notification_preferences_get', methods: ['GET'])]
    public function preferences(): JsonResponse
    {
        return $this->json($this->serialize($this->preferences->findForUser($this->getUser())));
    }

    #[Route('/preferencias', name: 'api_notification_preferences_update', methods: ['PUT', 'PATCH'])]
    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);
        $preference = $this->preferences->findForUser($this->getUser());

        if (array_key_exists('emailHabilitado', $data)) {
            $preference->setEmailEnabled((bool) $data['emailHabilitado']);
        }

        if (array_key_exists('tipos', $data)) {
            if (!is_array($data['tipos'])) {
                return $this->json(['erro' => 'O campo tipos deve ser uma lista.'], 400);
            }

            $preference->setTypes(array_map(static fn(mixed $type): string => (string) $type, $data['tipos']));
        }

        $this->preferences->getEntityManager()->flush();

        return $this->json($this->serialize($preference));
    }

    #[Route('/eventos', name: 'api_notification_event_create', methods: ['POST'])]
    public function createEvent(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $data = $this->jsonBody($request);
        $title = trim((string) ($data['titulo'] ?? ''));
        $body = trim((string) ($data['corpo'] ?? ''));
        $link = isset($data['link']) ? trim((string) $data['link']) : null;

        if ($title === '' || $body === '') {
            return $this->json(['erro' => 'Informe titulo e corpo.'], 400);
        }

        if (mb_strlen($title) > self::MAX_TITLE || mb_strlen($body) > self::MAX_BODY) {
            return $this->json(['erro' => 'Titulo ou corpo excede o limite.'], 400);
        }

        if ($link !== null && (mb_strlen($link) > self::MAX_LINK || !filter_var($link, FILTER_VALIDATE_URL))) {
            return $this->json(['erro' => 'Link inválido.'], 400);
        }

        $event = $this->notifications->dispatch(
            (string) ($data['tipo'] ?? ''),
            strip_tags($title),
            strip_tags($body),
            $link,
            isset($data['entidadeTipo']) ? mb_substr(trim((string) $data['entidadeTipo']), 0, 40) : null,
            isset($data['entidadeId']) ? (int) $data['entidadeId'] : null,
            $this->getUser(),
        );

        return $this->json([
            'id' => $event->getId(),
            'tipo' => $event->getType(),
            'criadoEm' => $event->getCreatedAt()->format(DATE_ATOM),
        ], 201);
    }

    #[Route('/enviar', name: 'api_notification_send_pending', methods: ['POST'])]
    public function sendPending(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->json($this->notifications->sendPending(min(500, max(1, $request->query->getInt('limite', 100)))));
    }

    private function jsonBody(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '{}', true);

        return is_array($data) ? $data : [];
    }

    private function serialize(NotificationPreference $preference): array
    {
        return [
            'emailHabilitado' => $preference->isEmailEnabled(),
            'tipos' => $preference->getTypes(),
            'tiposDisponiveis' => NotificationPreference::TIPOS,
            'atualizadoEm' => $preference->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
