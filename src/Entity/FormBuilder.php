<?php

namespace App\Entity;

use App\Repository\FormBuilderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FormBuilderRepository::class)]
#[ORM\Table(name: 'form_builder')]
class FormBuilder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $nome = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $descricao = null;

    /** Slug único usado na URL: /forms/{slug} */
    #[ORM\Column(length: 80, unique: true)]
    private string $slug = '';

    /** JSON com regras globais: redirect_url, success_message, email_notificacao, etc. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $configuracoes = null;

    #[ORM\Column]
    private bool $ativo = true;

    #[ORM\Column]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $criadoPor = null;

    #[ORM\OneToMany(mappedBy: 'formulario', targetEntity: FormBuilderCampo::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EAGER')]
    #[ORM\OrderBy(['ordem' => 'ASC'])]
    private Collection $campos;

    #[ORM\OneToMany(mappedBy: 'formulario', targetEntity: FormBuilderResposta::class, cascade: ['remove'])]
    private Collection $respostas;

    public function __construct()
    {
        $this->campos    = new ArrayCollection();
        $this->respostas = new ArrayCollection();
        $this->criadoEm  = new \DateTimeImmutable();
    }

    // ── getters / setters ──────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getNome(): string { return $this->nome; }
    public function setNome(string $nome): static { $this->nome = $nome; return $this; }

    public function getDescricao(): ?string { return $this->descricao; }
    public function setDescricao(?string $descricao): static { $this->descricao = $descricao; return $this; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getConfiguracoes(): ?array { return $this->configuracoes; }
    public function setConfiguracoes(?array $configuracoes): static { $this->configuracoes = $configuracoes; return $this; }

    public function isAtivo(): bool { return $this->ativo; }
    public function setAtivo(bool $ativo): static { $this->ativo = $ativo; return $this; }

    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }

    public function getAtualizadoEm(): ?\DateTimeImmutable { return $this->atualizadoEm; }
    public function setAtualizadoEm(?\DateTimeImmutable $dt): static { $this->atualizadoEm = $dt; return $this; }

    public function getCriadoPor(): ?User { return $this->criadoPor; }
    public function setCriadoPor(?User $user): static { $this->criadoPor = $user; return $this; }

    /** @return Collection<int, FormBuilderCampo> */
    public function getCampos(): Collection { return $this->campos; }

    public function addCampo(FormBuilderCampo $campo): static
    {
        if (!$this->campos->contains($campo)) {
            $this->campos->add($campo);
            $campo->setFormulario($this);
        }
        return $this;
    }

    public function removeCampo(FormBuilderCampo $campo): static
    {
        if ($this->campos->removeElement($campo)) {
            if ($campo->getFormulario() === $this) {
                $campo->setFormulario(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, FormBuilderResposta> */
    public function getRespostas(): Collection { return $this->respostas; }
}
