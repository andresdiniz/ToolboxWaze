<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationDeliveryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationDeliveryRepository::class)]
#[ORM\Table(name: 'notificacao_envio_email')]
#[ORM\UniqueConstraint(name: 'UNIQ_NOTIF_ENVIO_EVENTO_USER', columns: ['evento_id', 'user_id'])]
#[ORM\Index(name: 'idx_notif_envio_estado', columns: ['estado', 'proxima_tentativa_em'])]
class NotificationDelivery
{
    public const ESTADO_PENDENTE = 'pendente';
    public const ESTADO_ENVIADO = 'enviado';
    public const ESTADO_FALHA = 'falha';

    private const MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NotificationEvent::class)]
    #[ORM\JoinColumn(name: 'evento_id', nullable: false, onDelete: 'CASCADE')]
    private NotificationEvent $event;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 20)]
    private string $status = self::ESTADO_PENDENTE;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(name: 'ultimo_erro', length: 500, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'criado_em', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'enviado_em', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'proxima_tentativa_em', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    public function __construct(NotificationEvent $event, User $user, string $email)
    {
        $email = trim($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('E-mail de destino inválido.');
        }

        $this->event = $event;
        $this->user = $user;
        $this->email = mb_strtolower($email);
        $this->createdAt = new \DateTimeImmutable();
        $this->nextAttemptAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEvent(): NotificationEvent { return $this->event; }
    public function getUser(): User { return $this->user; }
    public function getEmail(): string { return $this->email; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getLastError(): ?string { return $this->lastError; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getNextAttemptAt(): ?\DateTimeImmutable { return $this->nextAttemptAt; }

    public function canRetry(): bool
    {
        return $this->status === self::ESTADO_PENDENTE
            && $this->attempts < self::MAX_ATTEMPTS
            && ($this->nextAttemptAt === null || $this->nextAttemptAt <= new \DateTimeImmutable());
    }

    public function markSent(): static
    {
        $this->status = self::ESTADO_ENVIADO;
        $this->sentAt = new \DateTimeImmutable();
        $this->lastError = null;
        $this->nextAttemptAt = null;

        return $this;
    }

    public function markFailed(string $error): static
    {
        $this->attempts++;
        $this->lastError = mb_substr($error, 0, 500);

        if ($this->attempts >= self::MAX_ATTEMPTS) {
            $this->status = self::ESTADO_FALHA;
            $this->nextAttemptAt = null;

            return $this;
        }

        $delay = 5 * 60 * (2 ** ($this->attempts - 1));
        $this->nextAttemptAt = (new \DateTimeImmutable())->modify('+' . $delay . ' seconds');

        return $this;
    }
}
