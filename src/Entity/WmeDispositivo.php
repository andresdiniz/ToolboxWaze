<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Dispositivo (instalação do userscript WME) vinculado a um usuário.
 *
 * O userscript gera um token aleatório de 64 hex e o envia em X-API-TOKEN.
 * O servidor guarda apenas sha256(token). O dispositivo nasce "pendente" e só
 * vira "ativo" depois que o dono do e-mail confirma o link enviado por e-mail.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wme_dispositivo')]
#[ORM\Index(name: 'idx_wme_disp_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_wme_disp_ip', columns: ['ip_hash', 'criado_em'])]
class WmeDispositivo
{
    public const ESTADO_PENDENTE = 'pendente';
    public const ESTADO_ATIVO    = 'ativo';
    public const ESTADO_REVOGADO = 'revogado';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'token_hash', length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $estado = self::ESTADO_PENDENTE;

    /** sha256 do verificador enviado por e-mail (uso único). */
    #[ORM\Column(name: 'verificacao_hash', length: 64, nullable: true)]
    private ?string $verificacaoHash = null;

    #[ORM\Column(name: 'verificacao_expira_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verificacaoExpiraEm = null;

    #[ORM\Column(name: 'verificacao_enviada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verificacaoEnviadaEm = null;

    /** Apelido do WME informado pelo script (não verificado). */
    #[ORM\Column(name: 'apelido_declarado', length: 120, nullable: true)]
    private ?string $apelidoDeclarado = null;

    #[ORM\Column(name: 'script_version', length: 20, nullable: true)]
    private ?string $scriptVersion = null;

    /** HMAC do IP (apenas para limitar abuso no cadastro). */
    #[ORM\Column(name: 'ip_hash', length: 64, nullable: true)]
    private ?string $ipHash = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'ativado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ativadoEm = null;

    #[ORM\Column(name: 'ultimo_uso_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ultimoUsoEm = null;

    public function __construct(string $tokenHash, User $user)
    {
        $this->tokenHash = $tokenHash;
        $this->user      = $user;
        $this->criadoEm  = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function getUser(): User { return $this->user; }

    public function getEstado(): string { return $this->estado; }
    public function isPendente(): bool { return $this->estado === self::ESTADO_PENDENTE; }
    public function isAtivo(): bool { return $this->estado === self::ESTADO_ATIVO; }
    public function isRevogado(): bool { return $this->estado === self::ESTADO_REVOGADO; }

    public function ativar(): static
    {
        $this->estado              = self::ESTADO_ATIVO;
        $this->ativadoEm           = new \DateTimeImmutable();
        $this->verificacaoHash     = null;
        $this->verificacaoExpiraEm = null;
        return $this;
    }

    public function revogar(): static
    {
        $this->estado              = self::ESTADO_REVOGADO;
        $this->verificacaoHash     = null;
        $this->verificacaoExpiraEm = null;
        return $this;
    }

    public function definirVerificacao(string $verificadorHash, \DateTimeImmutable $expiraEm): static
    {
        $this->verificacaoHash       = $verificadorHash;
        $this->verificacaoExpiraEm   = $expiraEm;
        $this->verificacaoEnviadaEm  = new \DateTimeImmutable();
        return $this;
    }

    public function getVerificacaoHash(): ?string { return $this->verificacaoHash; }
    public function getVerificacaoExpiraEm(): ?\DateTimeImmutable { return $this->verificacaoExpiraEm; }
    public function getVerificacaoEnviadaEm(): ?\DateTimeImmutable { return $this->verificacaoEnviadaEm; }

    public function getApelidoDeclarado(): ?string { return $this->apelidoDeclarado; }
    public function setApelidoDeclarado(?string $v): static { $this->apelidoDeclarado = $v; return $this; }

    public function getScriptVersion(): ?string { return $this->scriptVersion; }
    public function setScriptVersion(?string $v): static { $this->scriptVersion = $v; return $this; }

    public function getIpHash(): ?string { return $this->ipHash; }
    public function setIpHash(?string $v): static { $this->ipHash = $v; return $this; }

    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }
    public function getAtivadoEm(): ?\DateTimeImmutable { return $this->ativadoEm; }
    public function getUltimoUsoEm(): ?\DateTimeImmutable { return $this->ultimoUsoEm; }
    public function setUltimoUsoEm(?\DateTimeImmutable $v): static { $this->ultimoUsoEm = $v; return $this; }
}
