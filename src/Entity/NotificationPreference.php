<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationPreferenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationPreferenceRepository::class)]
#[ORM\Table(name: 'notificacao_preferencia')]
#[ORM\UniqueConstraint(name: 'UNIQ_NOTIF_PREF_USER', columns: ['user_id'])]
class NotificationPreference
{
    public const TIPOS = [
        'radar_criado',
        'radar_atualizado',
        'radar_validade',
        'solicitacao_status',
        'solicitacao_comentario',
        'dispositivo_expira',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'email_habilitado')]
    private bool $emailEnabled = true;

    #[ORM\Column(type: Types::JSON)]
    private array $types = self::TIPOS;

    #[ORM\Column(name: 'atualizado_em', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }

    public function isEmailEnabled(): bool { return $this->emailEnabled; }

    public function setEmailEnabled(bool $enabled): static
    {
        $this->emailEnabled = $enabled;
        $this->touch();

        return $this;
    }

    /** @return list<string> */
    public function getTypes(): array
    {
        return array_values(array_intersect($this->types, self::TIPOS));
    }

    /** @param list<string> $types */
    public function setTypes(array $types): static
    {
        $this->types = array_values(array_unique(array_intersect($types, self::TIPOS)));
        $this->touch();

        return $this;
    }

    public function accepts(string $type): bool
    {
        return $this->emailEnabled && in_array($type, $this->getTypes(), true);
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
