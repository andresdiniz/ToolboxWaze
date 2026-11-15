<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SolicitacaoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SolicitacaoRepository::class)]
#[ORM\Table(name: 'solicitacoes')]
#[ORM\HasLifecycleCallbacks]
class Solicitacao
{
    public const TIPO_IMAGEM_SATELITE = 'imagem_satelite';
    public const TIPO_GERENTE_AREA = 'gerente_area';
    public const TIPO_GERENTE_ESTADO_PAIS = 'gerente_estado_pais';
    public const TIPO_NIVEL = 'nivel';
    public const TIPO_OOPS = 'oops';
    public const TIPO_BANDEIRA_POSTO = 'bandeira_posto';
    public const TIPO_ID_SEGMENTO = 'id_segmento';

    /**
     * @deprecated Use o slug do FormBuilder como tipo para novos formulários.
     */
    public const TIPOS = [
        self::TIPO_IMAGEM_SATELITE => 'Imagem de Satélite',
        self::TIPO_GERENTE_AREA => 'Gerente de Área',
        self::TIPO_GERENTE_ESTADO_PAIS => 'Gerente de Estado ou País',
        self::TIPO_NIVEL => 'Nível (Upgrade/Downgrade)',
        self::TIPO_OOPS => 'Oops de Editor',
        self::TIPO_BANDEIRA_POSTO => 'Bandeira de Posto de Gasolina',
        self::TIPO_ID_SEGMENTO => 'Cadastro de ID de Segmento',
    ];

    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_EM_ANALISE = 'em_analise';
    public const STATUS_EM_ANDAMENTO = 'em_andamento';
    public const STATUS_AGUARDANDO = 'aguardando';
    public const STATUS_RESOLVIDA = 'resolvida';
    public const STATUS_NEGADA = 'negada';
    public const STATUS_CANCELADA = 'cancelada';

    public const STATUS_LABELS = [
        self::STATUS_PENDENTE => 'Pendente',
        self::STATUS_EM_ANALISE => 'Em análise',
        self::STATUS_EM_ANDAMENTO => 'Em andamento',
        self::STATUS_AGUARDANDO => 'Aguardando retorno',
        self::STATUS_RESOLVIDA => 'Resolvida',
        self::STATUS_NEGADA => 'Negada',
        self::STATUS_CANCELADA => 'Cancelada',
    ];

    public const STATUS_CORES = [
        self::STATUS_PENDENTE => 'warning',
        self::STATUS_EM_ANALISE => 'info',
        self::STATUS_EM_ANDAMENTO => 'primary',
        self::STATUS_AGUARDANDO => 'secondary',
        self::STATUS_RESOLVIDA => 'success',
        self::STATUS_NEGADA => 'danger',
        self::STATUS_CANCELADA => 'dark',
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

    #[ORM\Column(length: 64)]
    private string $tipo;

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

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dados = null;

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

    #[ORM\Column(name: 'segmento_id', type: 'string', length: 32, nullable: true)]
    private ?string $segmentoId = null;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'solicitacao_responsaveis')]
    private Collection $responsaveis;

    #[ORM\OneToMany(
        mappedBy: 'solicitacao',
        targetEntity: SolicitacaoHistorico::class,
        cascade: ['persist'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $historicos;

    #[ORM\OneToMany(
        mappedBy: 'solicitacao',
        targetEntity: SolicitacaoComentario::class,
        cascade: ['persist'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $comentarios;

    public function __construct()
    {
        $this->responsaveis = new ArrayCollection();
        $this->historicos = new ArrayCollection();
        $this->comentarios = new ArrayCollection();
        $this->criadaEm = new \DateTimeImmutable();
        $this->atualizadaEm = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function tocarAtualizadaEm(): void
    {
        $this->atualizadaEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): static
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getTipoLabel(): string
    {
        if (isset(self::TIPOS[$this->tipo])) {
            return self::TIPOS[$this->tipo];
        }

        if ($this->formulario !== null) {
            return $this->formulario->getNome();
        }

        return $this->tipo;
    }

    public function isDinamico(): bool
    {
        return $this->formulario !== null;
    }

    public function getFormulario(): ?FormBuilder
    {
        return $this->formulario;
    }

    public function setFormulario(?FormBuilder $formulario): static
    {
        $this->formulario = $formulario;

        return $this;
    }

    public function getDadosDinamicos(): ?array
    {
        return $this->dadosDinamicos;
    }

    public function setDadosDinamicos(?array $dadosDinamicos): static
    {
        $this->dadosDinamicos = $dadosDinamicos;

        return $this;
    }

    public function getDadoDinamico(string $chave, mixed $padrao = null): mixed
    {
        return $this->dadosDinamicos[$chave] ?? $padrao;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusCor(): string
    {
        return self::STATUS_CORES[$this->status] ?? 'secondary';
    }

    public function isPendente(): bool
    {
        return $this->status === self::STATUS_PENDENTE;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::STATUS_FINAIS, true);
    }

    public function getSolicitanteNome(): string
    {
        return $this->solicitanteNome;
    }

    public function setSolicitanteNome(string $solicitanteNome): static
    {
        $this->solicitanteNome = $solicitanteNome;

        return $this;
    }

    public function getSolicitanteUsuario(): string
    {
        return $this->solicitanteUsuario;
    }

    public function setSolicitanteUsuario(string $solicitanteUsuario): static
    {
        $this->solicitanteUsuario = $solicitanteUsuario;

        return $this;
    }

    public function getSolicitanteEmail(): string
    {
        return $this->solicitanteEmail;
    }

    public function setSolicitanteEmail(string $solicitanteEmail): static
    {
        $this->solicitanteEmail = $solicitanteEmail;

        return $this;
    }

    public function getEstado(): ?string
    {
        return $this->estado;
    }

    public function setEstado(?string $estado): static
    {
        $this->estado = $estado;

        return $this;
    }

    public function getDados(): ?array
    {
        return $this->dados;
    }

    public function setDados(?array $dados): static
    {
        $this->dados = $dados;

        return $this;
    }

    public function getDado(string $chave, mixed $padrao = null): mixed
    {
        return $this->dados[$chave] ?? $padrao;
    }

    public function getArquivos(): ?array
    {
        return $this->arquivos;
    }

    public function setArquivos(?array $arquivos): static
    {
        $this->arquivos = $arquivos;

        return $this;
    }

    public function getResolvidaPor(): ?User
    {
        return $this->resolvidaPor;
    }

    public function setResolvidaPor(?User $resolvidaPor): static
    {
        $this->resolvidaPor = $resolvidaPor;

        return $this;
    }

    public function getResolvidaEm(): ?\DateTimeImmutable
    {
        return $this->resolvidaEm;
    }

    public function setResolvidaEm(?\DateTimeImmutable $resolvidaEm): static
    {
        $this->resolvidaEm = $resolvidaEm;

        return $this;
    }

    public function getNotaResolucao(): ?string
    {
        return $this->notaResolucao;
    }

    public function setNotaResolucao(?string $notaResolucao): static
    {
        $this->notaResolucao = $notaResolucao;

        return $this;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getAtualizadaEm(): \DateTimeImmutable
    {
        return $this->atualizadaEm;
    }

    public function getSegmentoId(): ?string
    {
        return $this->segmentoId;
    }

    public function setSegmentoId(?string $segmentoId): static
    {
        $this->segmentoId = $segmentoId;

        return $this;
    }

    public function getResponsaveis(): Collection
    {
        return $this->responsaveis;
    }

    public function addResponsavel(User $usuario): static
    {
        if (!$this->responsaveis->contains($usuario)) {
            $this->responsaveis->add($usuario);
        }

        return $this;
    }

    public function removeResponsavel(User $usuario): static
    {
        $this->responsaveis->removeElement($usuario);

        return $this;
    }

    public function getHistoricos(): Collection
    {
        return $this->historicos;
    }

    public function addHistorico(SolicitacaoHistorico $historico): static
    {
        if (!$this->historicos->contains($historico)) {
            $this->historicos->add($historico);
            $historico->setSolicitacao($this);
        }

        return $this;
    }

    public function getComentarios(): Collection
    {
        return $this->comentarios;
    }

    public function addComentario(SolicitacaoComentario $comentario): static
    {
        if (!$this->comentarios->contains($comentario)) {
            $this->comentarios->add($comentario);
            $comentario->setSolicitacao($this);
        }

        return $this;
    }
}