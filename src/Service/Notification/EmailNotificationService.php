<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\NotificationDelivery;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Repository\NotificationDeliveryRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Service\EmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class EmailNotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationPreferenceRepository $preferences,
        private readonly NotificationDeliveryRepository $deliveries,
        private readonly EmailSender $emailSender,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function dispatch(
        string $type,
        string $title,
        string $body,
        ?string $link = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?User $createdBy = null,
    ): NotificationEvent {
        $event = new NotificationEvent(
            $type,
            $title,
            $body,
            $link,
            $entityType,
            $entityId,
            $createdBy
        );

        $this->em->persist($event);

        foreach ($this->preferences->findEmailSubscribers($type) as $preference) {
            $this->createDelivery($event, $preference->getUser(), $createdBy);
        }

        $this->em->flush();

        return $event;
    }

    /**
     * @param list<User> $recipients
     */
    public function dispatchToUsers(
        string $type,
        string $title,
        string $body,
        array $recipients,
        ?string $link = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?User $createdBy = null,
    ): ?NotificationEvent {
        $recipients = array_values(array_filter(
            $recipients,
            static fn (mixed $user): bool => $user instanceof User
        ));

        if ($recipients === []) {
            return null;
        }

        $event = new NotificationEvent(
            $type,
            $title,
            $body,
            $link,
            $entityType,
            $entityId,
            $createdBy
        );

        $this->em->persist($event);

        foreach ($recipients as $user) {
            $this->createDelivery($event, $user, $createdBy);
        }

        $this->em->flush();

        return $event;
    }

    private function createDelivery(
        NotificationEvent $event,
        User $user,
        ?User $exclude
    ): void {
        if ($exclude !== null && $user->getId() === $exclude->getId()) {
            return;
        }

        $preference = $this->preferences->findForUser($user);

        if (!$preference->accepts($event->getType())) {
            return;
        }

        $email = trim((string) $user->getEmail());

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        if ($this->deliveries->findOneBy([
            'event' => $event,
            'user' => $user,
        ]) !== null) {
            return;
        }

        $this->em->persist(new NotificationDelivery($event, $user, $email));
    }

    /**
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function sendPending(int $limit = 100): array
    {
        $result = [
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($this->deliveries->findPending($limit) as $delivery) {
            if (!$delivery->canRetry()) {
                $result['skipped']++;
                continue;
            }

            $event = $delivery->getEvent();
            $link = $event->getLink();

            if ($link !== null && !str_starts_with($link, 'http://') && !str_starts_with($link, 'https://')) {
                $link = $this->urlGenerator->generate(
                    'public_radar_index',
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );
            }

            try {
                $html = $this->twig->render('emails/notification.html.twig', [
                    'event' => $event,
                    'link' => $link,
                ]);

                $this->emailSender->send(
                    $delivery->getEmail(),
                    null,
                    '[ToolboxWaze] ' . $event->getTitle(),
                    $html
                );

                $delivery->markSent();
                $result['sent']++;
            } catch (\Throwable $exception) {
                $delivery->markFailed($exception->getMessage());
                $result['failed']++;

                $this->logger->warning('Falha ao enviar notificação por e-mail.', [
                    'delivery' => $delivery->getId(),
                    'event' => $event->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->em->flush();
        }

        return $result;
    }
}