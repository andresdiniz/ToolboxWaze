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
    // ── Tipos legados (mantidos para compatibilidade com dados existentes) ────
    public const TIPO_IMAGEM_SATELITE       = 'imagem_satelite';
    public const TIPO_GERENTE_AREA          = 'gerente_area';
    public const TIPO_GERENTE_ESTADO_PAIS   = 'gerente_estado_pais';
    public const TIPO_NIVEL                 = 'nivel';
    public const TIPO_OOPS                  = 'oops';
    public const TIPO_BANDEIRA_POSTO        = 'bandeira_posto';
    public const TIPO_ID_SEGMENTO           = 'id_segmento';

    /** @deprecated Use FormBuilder slug como tipo para novos forms */
    public const TIPOS = [
        self::TIPO_IMAGEM_SATELITE     => 'Imagem de Satélite',
        self::TIPO_GERENTE_AREA        => 'Gerente de Área',
        self::TIPO_GERENTE_ESTADO_PAIS => 'Gerente de Estado ou País',
        self::TIPO_NIVEL               => 'Nível (Upgrade/Downgrade)',
        self::TIPO_OOPS                => 'Oops de Editor',
        self::TIPO_BANDEIRA_POSTO      => 'Bandeira de Posto de Gasolina',
        self::TIPO_ID_SEGMENTO         => 'Cadastro de ID de Segmento',
    ];

    // Status completos do fluxo
    public const STATUS_PENDENTE      = 'pendente';
    public const STATUS_EM_ANALISE    = 'em_analise';
    public const STATUS_EM_ANDAMENTO  = 'em_andamento';
    public const STATUS_AGUARDANDO    = 'aguardando';
    public const STATUS_RESOLVIDA     = 'resolvida';
    public const STATUS_NEGADA        = 'negada';
    public const STATUS_CANCELADA     = 'cancelada';

    public const STATUS_LABELS = [
        self::STATUS_PENDENTE     => 'Pendente',
        self::STATUS_EM_ANALISE   => 'Em análise',
        self::STATUS_EM_ANDAMENTO => 'Em andamento',
        self::STATUS_AGUARDANDO   => 'Aguardando retorno',
        self::STATUS_RESOLVIDA    => 'Resolvida',
        self::STATUS_NEGADA       => 'Negada',
        self::STATUS_CANCELADA    => 'Cancelada',
    ];

    public const STATUS_CORES = [
        self::STATUS_PENDENTE     => 'warning',
        self::STATUS_EM_ANALISE   => 'info',
        self::STATUS_EM_ANDAMENTO => 'primary',
        self::STATUS_AGUARDANDO   => 'secondary',
        self::STATUS_RESOLVIDA    => 'success',
        self::STATUS_NEGADA       => 'danger',
        self::STATUS_CANCELADA    => 'dark',
    ];

    public const STATUS_FINAIS = [
        self::STATUS_RESOLVIDA,
        self::STATUS_NEGADA,
        self::STATUS_CANCELADA,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Para solicitações legadas: um dos TIPOS hardcoded.
     * Para novos forms dinâmicos: o slug do FormBuilder (ex: 'mudanca-nivel').
     */
    #[ORM\Column(length: 64)]
    private string $tipo;

    /**
     * FK para FormBuilder — preenchida apenas em solicitações criadas via form dinâmico.
     * Null para solicitações legadas.
     */
    #[ORM\ManyToOne(targetEntity: FormBuilder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FormBuilder $formulario = null;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_PENDENTE;

    #[ORM\Column(length: 255)]
    private string $solicitanteNome;

    #[ORM\Column(length: 255)]
    private string $solicitanteUsuario;

    #[ORM\Column(length: 255)]
    private string $solicitanteEmail;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $estado = null;

    /** Dados legados (campos fixos do tipo) */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dados = null;

    /** Respostas dos campos dinâmicos do FormBuilder */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dadosDinamicos = null;

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

    #[ORM\OneToMany(mappedBy: 'solicitacao', targetEntity: SolicitacaoHistorico::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $historicos;

    #[ORM\OneToMany(mappedBy: 'solicitacao', targetEntity: SolicitacaoComentario::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $comentarios;

    public function __construct()
    {
        $this->responsaveis = new ArrayCollection();
        $this->historicos   = new ArrayCollection();
        $this->comentarios  = new ArrayCollection();
        $this->criadaEm     = new \DateTimeImmutable();
        $this->atualizadaEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->atualizadaEm = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    // ── tipo ─────────────────────────────────────────────────────────────────

    public function getTipo(): string { return $this->tipo; }

    /**
     * Aceita tanto tipos legados (array TIPOS) quanto slugs de FormBuilder.
     */
    public function setTipo(string $tipo): static
    {
        $this->tipo = $tipo;
        return $this;
    }

    public function getTipoLabel(): string
    {
        // Tipo legado
        if (isset(self::TIPOS[$this->tipo])) {
            return self::TIPOS[$this->tipo];
        }
        // Form dinâmico: usa nome do FormBuilder se disponível
        if ($this->formulario !== null) {
            return $this->formulario->getNome();
        }
        return $this->tipo;
    }

    public function isDinamico(): bool
    {
        return $this->formulario !== null;
    }

    // ── formulario ───────────────────────────────────────────────────────────

    public function getFormulario(): ?FormBuilder { return $this->formulario; }
    public function setFormulario(?FormBuilder $f): static { $this->formulario = $f; return $this; }

    // ── dadosDinamicos ───────────────────────────────────────────────────────

    public function getDadosDinamicos(): ?array { return $this->dadosDinamicos; }
    public function setDadosDinamicos(?array $v): static { $this->dadosDinamicos = $v; return $this; }
    public function getDadoDinamico(string $key, mixed $default = null): mixed
    {
        return $this->dadosDinamicos[$key] ?? $default;
    }

    // ── status ───────────────────────────────────────────────────────────────

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getStatusLabel(): string { return self::STATUS_LABELS[$this->status] ?? $this->status; }
    public function getStatusCor(): string { return self::STATUS_CORES[$this->status] ?? 'secondary'; }
    public function isPendente(): bool { return $this->status === self::STATUS_PENDENTE; }
    public function isFinal(): bool { return in_array($this->status, self::STATUS_FINAIS, true); }

    // ── solicitante ──────────────────────────────────────────────────────────

    public function getSolicitanteNome(): string { return $this->solicitanteNome; }
    public function setSolicitanteNome(string $v): static { $this->solicitanteNome = $v; return $this; }

    public function getSolicitanteUsuario(): string { return $this->solicitanteUsuario; }
    public function setSolicitanteUsuario(string $v): static { $this->solicitanteUsuario = $v; return $this; }

    public function getSolicitanteEmail(): string { return $this->solicitanteEmail; }
    public function setSolicitanteEmail(string $v): static { $this->solicitanteEmail = $v; return $this; }

    // ── outros ───────────────────────────────────────────────────────────────

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

    public function getHistoricos(): Collection { return $this->historicos; }
    public function addHistorico(SolicitacaoHistorico $h): static
    {
        if (!$this->historicos->contains($h)) {
            $this->historicos->add($h);
            $h->setSolicitacao($this);
        }
        return $this;
    }

    public function getComentarios(): Collection { return $this->comentarios; }
    public function addComentario(SolicitacaoComentario $c): static
    {
        if (!$this->comentarios->contains($c)) {
            $this->comentarios->add($c);
            $c->setSolicitacao($this);
        }
        return $this;
    }
}
