<?php

namespace App\Entity;

use App\Repository\SolicitacaoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SolicitacaoRepository::class)]
#[ORM\Table(name: "solicitacoes")]
#[ORM\HasLifecycleCallbacks]
class Solicitacao
{
    public const TIPO_IMAGEM_SATELITE       = 'imagem_satelite';
    public const TIPO_GERENTE_AREA          = 'gerente_area';
    public const TIPO_GERENTE_ESTADO_PAIS   = 'gerente_estado_pais';
    public const TIPO_NIVEL                 = 'nivel';
    public const TIPO_OOPS                  = 'oops';
    public const TIPO_BANDEIRA_POSTO        = 'bandeira_posto';
    public const TIPO_ID_SEGMENTO           = 'id_segmento';

    public const TIPOS = [
        self::TIPO_IMAGEM_SATELITE     => 'Imagem de Satélite',
        self::TIPO_GERENTE_AREA        => 'Gerente de Área',
        self::TIPO_GERENTE_ESTADO_PAIS => 'Gerente de Estado ou País',
        self::TIPO_NIVEL               => 'Nível (Upgrade/Downgrade)',
        self::TIPO_OOPS                => 'Oops de Editor',
        self::TIPO_BANDEIRA_POSTO      => 'Bandeira de Posto de Gasolina',
        self::TIPO_ID_SEGMENTO         => 'Cadastro de ID de Segmento',
    ];

    public const STATUS_PENDENTE   = 'pendente';
    public const STATUS_RESOLVIDA  = 'resolvida';
    public const STATUS_CANCELADA  = 'cancelada';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tipo;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDENTE;

    #[ORM\Column(length: 255)]
    private string $solicitanteNome;

    #[ORM\Column(length: 255)]
    private string $solicitanteUsuario;

    #[ORM\Column(length: 255)]
    private string $solicitanteEmail;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $estado = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dados = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $arquivos = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $resolvidaPor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvidaEm = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notaResolucao = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadaEm;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'solicitacao_responsaveis')]
    private Collection $responsaveis;

    public function __construct()
    {
        $this->responsaveis = new ArrayCollection();
        $this->criadaEm     = new \DateTimeImmutable();
        $this->atualizadaEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->atualizadaEm = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTipo(): string { return $this->tipo; }
    public function setTipo(string $tipo): static
    {
        if (!array_key_exists($tipo, self::TIPOS)) {
            throw new \InvalidArgumentException("Tipo inválido: $tipo");
        }
        $this->tipo = $tipo;
        return $this;
    }
    public function getTipoLabel(): string { return self::TIPOS[$this->tipo] ?? $this->tipo; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function isPendente(): bool { return $this->status === self::STATUS_PENDENTE; }

    public function getSolicitanteNome(): string { return $this->solicitanteNome; }
    public function setSolicitanteNome(string $v): static { $this->solicitanteNome = $v; return $this; }

    public function getSolicitanteUsuario(): string { return $this->solicitanteUsuario; }
    public function setSolicitanteUsuario(string $v): static { $this->solicitanteUsuario = $v; return $this; }

    public function getSolicitanteEmail(): string { return $this->solicitanteEmail; }
    public function setSolicitanteEmail(string $v): static { $this->solicitanteEmail = $v; return $this; }

    public function getEstado(): ?string { return $this->estado; }
    public function setEstado(?string $v): static { $this->estado = $v; return $this; }

    public function getDados(): ?array { return $this->dados; }
    public function setDados(?array $v): static { $this->dados = $v; return $this; }
    public function getDado(string $key, mixed $default = null): mixed { return $this->dados[$key] ?? $default; }

    public function getArquivos(): ?array { return $this->arquivos; }
    public function setArquivos(?array $v): static { $this->arquivos = $v; return $this; }

    public function getResolvidaPor(): ?User { return $this->resolvidaPor; }
    public function setResolvidaPor(?User $u): static { $this->resolvidaPor = $u; return $this; }

    public function getResolvidaEm(): ?\DateTimeImmutable { return $this->resolvidaEm; }
    public function setResolvidaEm(?\DateTimeImmutable $v): static { $this->resolvidaEm = $v; return $this; }

    public function getNotaResolucao(): ?string { return $this->notaResolucao; }
    public function setNotaResolucao(?string $v): static { $this->notaResolucao = $v; return $this; }

    public function getCriadaEm(): \DateTimeImmutable { return $this->criadaEm; }
    public function getAtualizadaEm(): \DateTimeImmutable { return $this->atualizadaEm; }

    public function getResponsaveis(): Collection { return $this->responsaveis; }
    public function addResponsavel(User $u): static
    {
        if (!$this->responsaveis->contains($u)) { $this->responsaveis->add($u); }
        return $this;
    }
    public function removeResponsavel(User $u): static
    {
        $this->responsaveis->removeElement($u);
        return $this;
    }
}
