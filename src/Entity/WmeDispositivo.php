<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WmeDispositivoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WmeDispositivoRepository::class)]
#[ORM\Table(name: 'wme_dispositivo')]
#[ORM\Index(columns: ['token_hash'], name: 'IDX_WME_DISPOSITIVO_TOKEN')]
#[ORM\Index(columns: ['estado', 'ultimo_uso_em'], name: 'IDX_WME_DISPOSITIVO_ESTADO_USO')]
class WmeDispositivo
{
    public const ESTADO_PENDENTE = 'pendente';
    public const ESTADO_ATIVO = 'ativo';
    public const ESTADO_REVOGADO = 'revogado';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'apelido_declarado', type: Types::STRING, length: 60, nullable: true)]
    private ?string $apelidoDeclarado = null;

    #[ORM\Column(name: 'script_version', type: Types::STRING, length: 20, nullable: true)]
    private ?string $scriptVersion = null;

    #[ORM\Column(name: 'ip_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $ipHash = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $estado = self::ESTADO_PENDENTE;

    #[ORM\Column(name: 'verificacao_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $verificacaoHash = null;

    #[ORM\Column(name: 'verificacao_enviada_em', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verificacaoEnviadaEm = null;

    #[ORM\Column(name: 'verificacao_expira_em', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verificacaoExpiraEm = null;

    #[ORM\Column(name: 'ativado_em', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $ativadoEm = null;

    #[ORM\Column(name: 'ultimo_uso_em', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $ultimoUsoEm = null;

    #[ORM\Column(name: 'criado_em', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $criadoEm;

    public function __construct(string $tokenHash, User $user)
    {
        $this->tokenHash = $tokenHash;
        $this->user = $user;
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getApelidoDeclarado(): ?string
    {
        return $this->apelidoDeclarado;
    }

    public function setApelidoDeclarado(?string $apelidoDeclarado): static
    {
        $this->apelidoDeclarado = $apelidoDeclarado;

        return $this;
    }

    public function getScriptVersion(): ?string
    {
        return $this->scriptVersion;
    }

    public function setScriptVersion(?string $scriptVersion): static
    {
        $this->scriptVersion = $scriptVersion;

        return $this;
    }

    public function getIpHash(): ?string
    {
        return $this->ipHash;
    }

    public function setIpHash(?string $ipHash): static
    {
        $this->ipHash = $ipHash;

        return $this;
    }

    public function getEstado(): string
    {
        return $this->estado;
    }

    public function setEstado(string $estado): static
    {
        $this->estado = $estado;

        return $this;
    }

    public function isPendente(): bool
    {
        return $this->estado === self::ESTADO_PENDENTE;
    }

    public function isAtivo(): bool
    {
        return $this->estado === self::ESTADO_ATIVO;
    }

    public function isRevogado(): bool
    {
        return $this->estado === self::ESTADO_REVOGADO;
    }

    public function isExpirado(): bool
    {
        $referencia = $this->ultimoUsoEm
            ?? $this->ativadoEm
            ?? $this->criadoEm;

        return $referencia < new \DateTimeImmutable('-' . 90 . ' days');
    }

    public function getVerificacaoHash(): ?string
    {
        return $this->verificacaoHash;
    }

    public function getVerificacaoEnviadaEm(): ?\DateTimeImmutable
    {
        return $this->verificacaoEnviadaEm;
    }

    public function getVerificacaoExpiraEm(): ?\DateTimeImmutable
    {
        return $this->verificacaoExpiraEm;
    }

    public function definirVerificacao(string $hash, \DateTimeImmutable $expiraEm): static
    {
        $this->verificacaoHash = $hash;
        $this->verificacaoEnviadaEm = new \DateTimeImmutable();
        $this->verificacaoExpiraEm = $expiraEm;

        return $this;
    }

    public function ativar(): static
    {
        $this->estado = self::ESTADO_ATIVO;
        $this->ativadoEm = new \DateTimeImmutable();
        $this->ultimoUsoEm = $this->ativadoEm;
        $this->verificacaoHash = null;
        $this->verificacaoExpiraEm = null;

        return $this;
    }

    public function revogar(): static
    {
        $this->estado = self::ESTADO_REVOGADO;

        return $this;
    }

    public function getAtivadoEm(): ?\DateTimeImmutable
    {
        return $this->ativadoEm;
    }

    public function getUltimoUsoEm(): ?\DateTimeImmutable
    {
        return $this->ultimoUsoEm;
    }

    public function setUltimoUsoEm(\DateTimeImmutable $ultimoUsoEm): static
    {
        $this->ultimoUsoEm = $ultimoUsoEm;

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}