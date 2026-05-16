<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Este e-mail já está cadastrado.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    // Permissões de área disponíveis no sistema
    public const PERMISSION_RADARS       = 'PERM_RADARS';
    public const PERMISSION_FUEL         = 'PERM_FUEL';
    public const PERMISSION_REPORTS      = 'PERM_REPORTS';
    public const PERMISSION_TOOLS        = 'PERM_TOOLS';
    public const PERMISSION_EXPORT       = 'PERM_EXPORT';

    public const ALL_PERMISSIONS = [
        self::PERMISSION_RADARS   => 'Radares',
        self::PERMISSION_FUEL     => 'Combustível',
        self::PERMISSION_REPORTS  => 'Relatórios',
        self::PERMISSION_TOOLS    => 'Ferramentas',
        self::PERMISSION_EXPORT   => 'Exportação de Dados',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $wazeNickname = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    /** @var list<string> permissões de área */
    #[ORM\Column]
    private array $permissions = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // --- Getters / Setters ---

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $v): static { $this->email = $v; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getWazeNickname(): ?string { return $this->wazeNickname; }
    public function setWazeNickname(?string $v): static { $this->wazeNickname = $v; return $this; }

    public function getUserIdentifier(): string { return $this->email; }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }
    public function setRoles(array $v): static { $this->roles = $v; return $this; }

    public function getPermissions(): array { return $this->permissions; }
    public function setPermissions(array $v): static { $this->permissions = $v; return $this; }
    public function hasPermission(string $perm): bool
    {
        return $this->isAdmin() || in_array($perm, $this->permissions, true);
    }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(?string $v): static { $this->password = $v; return $this; }

    public function eraseCredentials(): void {}

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function isPending(): bool  { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool { return $this->status === self::STATUS_APPROVED; }
    public function isRejected(): bool { return $this->status === self::STATUS_REJECTED; }

    public function getGoogleId(): ?string { return $this->googleId; }
    public function setGoogleId(?string $v): static { $this->googleId = $v; return $this; }

    public function getAvatarUrl(): ?string { return $this->avatarUrl; }
    public function setAvatarUrl(?string $v): static { $this->avatarUrl = $v; return $this; }

    public function getResetToken(): ?string { return $this->resetToken; }
    public function setResetToken(?string $v): static { $this->resetToken = $v; return $this; }

    public function getResetTokenExpiresAt(): ?\DateTimeImmutable { return $this->resetTokenExpiresAt; }
    public function setResetTokenExpiresAt(?\DateTimeImmutable $v): static { $this->resetTokenExpiresAt = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function setApprovedAt(?\DateTimeImmutable $v): static { $this->approvedAt = $v; return $this; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $v): static { $this->lastLoginAt = $v; return $this; }

    public function isAdmin(): bool { return in_array('ROLE_ADMIN', $this->getRoles(), true); }
}
