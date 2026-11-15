<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationEventRepository::class)]
#[ORM\Table(name: 'notificacao_evento')]
#[ORM\Index(name: 'idx_notif_evento_tipo', columns: ['tipo'])]
#[ORM\Index(name: 'idx_notif_evento_entidade', columns: ['entidade_tipo', 'entidade_id'])]
class NotificationEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $type;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $link = null;

    #[ORM\Column(name: 'entidade_tipo', length: 40, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(name: 'entidade_id', nullable: true)]
    private ?int $entityId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'criado_em', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $type,
        string $title,
        string $body,
        ?string $link = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?User $createdBy = null,
    ) {
        if (!in_array($type, NotificationPreference::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo de notificação inválido.');
        }

        $this->type = $type;
        $this->title = mb_substr(trim($title), 0, 180);
        $this->body = trim($body);
        $this->link = $link !== null ? mb_substr(trim($link), 0, 500) : null;
        $this->entityType = $entityType !== null ? mb_substr(trim($entityType), 0, 40) : null;
        $this->entityId = $entityId;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getTitle(): string { return $this->title; }
    public function getBody(): string { return $this->body; }
    public function getLink(): ?string { return $this->link; }
    public function getEntityType(): ?string { return $this->entityType; }
    public function getEntityId(): ?int { return $this->entityId; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
