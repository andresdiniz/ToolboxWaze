<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\NotificationEvent;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Repository\NotificationDeliveryRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Service\EmailSender;
use App\Service\Notification\EmailNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class EmailNotificationServiceTest extends TestCase
{
    public function testDispatchCreatesDeliveryOnlyForSubscribers(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeastOnce())->method('persist');
        $em->expects(self::once())->method('flush');

        $subscriber = $this->createUser(1, 'assinante@example.com');
        $author = $this->createUser(2, 'autor@example.com');
        $preference = new NotificationPreference($subscriber);
        $preference->setTypes(['radar_atualizado']);

        $preferences = $this->createMock(NotificationPreferenceRepository::class);
        $preferences->method('findEmailSubscribers')->willReturn([$preference]);

        $deliveries = $this->getMockBuilder(NotificationDeliveryRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $deliveries->method('findOneBy')->willReturn(null);

        $service = new EmailNotificationService(
            $em,
            $preferences,
            $deliveries,
            $this->createMock(EmailSender::class),
            $this->createMock(Environment::class),
            $this->createMock(UrlGeneratorInterface::class),
            new NullLogger(),
        );

        $event = $service->dispatch('radar_atualizado', 'Radar atualizado', 'Dados alterados.', null, 'radar', 10, $author);

        self::assertSame('radar_atualizado', $event->getType());
    }

    private function createUser(int $id, string $email): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getEmail')->willReturn($email);

        return $user;
    }
}
