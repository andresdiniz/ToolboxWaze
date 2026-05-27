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

    public const PERMISSION_RADARS  = 'PERM_RADARS';
    public const PERMISSION_FUEL    = 'PERM_FUEL';
    public const PERMISSION_REPORTS = 'PERM_REPORTS';
    public const PERMISSION_TOOLS   = 'PERM_TOOLS';
    public const PERMISSION_EXPORT  = 'PERM_EXPORT';

    public const ALL_PERMISSIONS = [
        self::PERMISSION_RADARS  => 'Radares',
        self::PERMISSION_FUEL    => 'Combustível',
        self::PERMISSION_REPORTS => 'Relatórios',
        self::PERMISSION_TOOLS   => 'Ferramentas',
        self::PERMISSION_EXPORT  => 'Exportação de Dados',
    ];

    public const ALL_UFS = [
        'AC','AL','AM','AP','BA','CE','DF','ES','GO',
        'MA','MG','MS','MT','PA','PB','PE','PI','PR',
        'RJ','RN','RO','RR','RS','SC','SE','SP','TO',
    ];

    /** Tipos de downgrade que um Champ pode processar */
    public const CHAMP_DOWNGRADE_TIPOS = [
        'radar'  => 'Radar',
        'posto'  => 'Posto de combustível',
        'camera' => 'Câmera',
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

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $solicitacaoTipos = [];

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedUfs = [];

    /** Limite diário de downgrades que o Champ pode processar (null = sem limite) */
    #[ORM\Column(nullable: true)]
    private ?int $champLimitDay = null;

    /** Limite mensal de downgrades que o Champ pode processar (null = sem limite) */
    #[ORM\Column(nullable: true)]
    private ?int $champLimitMonth = null;

    /**
     * Tipos de downgrade permitidos ao Champ (null = todos).
     * Valores possíveis: 'radar', 'posto', 'camera'
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $champDowngradeTipos = null;

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

    /**
     * Token de API pessoal do usuário (64 hex chars = 256 bits).
     * Gerado sob demanda. Nulo enquanto não solicitado.
     */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $apiToken = null;

    /** Data/hora em que o token de API foi gerado pela última vez. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $apiTokenGeneratedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // -------------------------------------------------------------------------
    // Getters / Setters
    // -------------------------------------------------------------------------

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
        $roles   = $this->roles;
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

    public function getSolicitacaoTipos(): ?array { return $this->solicitacaoTipos; }
    public function setSolicitacaoTipos(?array $tipos): static
    {
        $this->solicitacaoTipos = $tipos !== null ? array_values(array_unique($tipos)) : null;
        return $this;
    }

    public function podeTratar(string $tipo): bool
    {
        if ($this->isAdmin()) { return true; }
        if ($this->solicitacaoTipos === null) { return true; }
        return in_array($tipo, $this->solicitacaoTipos, true);
    }

    public function getAllowedUfs(): ?array { return $this->allowedUfs; }
    public function setAllowedUfs(?array $ufs): static
    {
        $this->allowedUfs = $ufs !== null ? array_values(array_unique($ufs)) : null;
        return $this;
    }

    public function canAccessUf(string $uf): bool
    {
        if ($this->isAdmin()) { return true; }
        if ($this->allowedUfs === null) { return true; }
        return in_array(strtoupper($uf), $this->allowedUfs, true);
    }

    public function getUfsForQuery(): ?array
    {
        if ($this->isAdmin() || $this->allowedUfs === null) { return null; }
        return $this->allowedUfs;
    }

    public function getChampLimitDay(): ?int { return $this->champLimitDay; }
    public function setChampLimitDay(?int $v): static { $this->champLimitDay = $v; return $this; }

    public function getChampLimitMonth(): ?int { return $this->champLimitMonth; }
    public function setChampLimitMonth(?int $v): static { $this->champLimitMonth = $v; return $this; }

    public function getChampDowngradeTipos(): ?array { return $this->champDowngradeTipos; }
    public function setChampDowngradeTipos(?array $tipos): static
    {
        $allowed = array_keys(self::CHAMP_DOWNGRADE_TIPOS);
        $this->champDowngradeTipos = $tipos !== null
            ? array_values(array_filter($tipos, fn($t) => in_array($t, $allowed, true)))
            : null;
        return $this;
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

    public function getApiToken(): ?string { return $this->apiToken; }
    public function setApiToken(?string $v): static { $this->apiToken = $v; return $this; }

    public function getApiTokenGeneratedAt(): ?\DateTimeImmutable { return $this->apiTokenGeneratedAt; }
    public function setApiTokenGeneratedAt(?\DateTimeImmutable $v): static { $this->apiTokenGeneratedAt = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function setApprovedAt(?\DateTimeImmutable $v): static { $this->approvedAt = $v; return $this; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $v): static { $this->lastLoginAt = $v; return $this; }

    public function isAdmin(): bool { return in_array('ROLE_ADMIN', $this->getRoles(), true); }
}
