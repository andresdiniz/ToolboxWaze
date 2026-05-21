<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'solicitacao_comentarios')]
class SolicitacaoComentario
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Solicitacao::class, inversedBy: 'comentarios')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Solicitacao $solicitacao;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $autor = null;

    /** Para solicitantes externos (não têm conta no sistema) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $autorNomeExterno = null;

    #[ORM\Column(type: 'text')]
    private string $mensagem;

    /** Visível apenas para responsáveis (comentário interno) */
    #[ORM\Column(type: 'boolean')]
    private bool $interno = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSolicitacao(): Solicitacao { return $this->solicitacao; }
    public function setSolicitacao(Solicitacao $s): static { $this->solicitacao = $s; return $this; }

    public function getAutor(): ?User { return $this->autor; }
    public function setAutor(?User $u): static { $this->autor = $u; return $this; }

    public function getAutorNomeExterno(): ?string { return $this->autorNomeExterno; }
    public function setAutorNomeExterno(?string $v): static { $this->autorNomeExterno = $v; return $this; }

    public function getAutorNome(): string
    {
        if ($this->autor) {
            return $this->autor->getName();
        }
        return $this->autorNomeExterno ?? 'Solicitante';
    }

    public function isResponsavel(): bool
    {
        return $this->autor !== null;
    }

    public function getMensagem(): string { return $this->mensagem; }
    public function setMensagem(string $v): static { $this->mensagem = $v; return $this; }

    public function isInterno(): bool { return $this->interno; }
    public function setInterno(bool $v): static { $this->interno = $v; return $this; }

    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }
}
