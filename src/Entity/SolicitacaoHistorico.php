<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'solicitacao_historicos')]
class SolicitacaoHistorico
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Solicitacao::class, inversedBy: 'historicos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Solicitacao $solicitacao;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $autor = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $statusAnterior = null;

    #[ORM\Column(length: 32)]
    private string $statusNovo;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nota = null;

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

    public function getStatusAnterior(): ?string { return $this->statusAnterior; }
    public function setStatusAnterior(?string $v): static { $this->statusAnterior = $v; return $this; }

    public function getStatusNovo(): string { return $this->statusNovo; }
    public function setStatusNovo(string $v): static { $this->statusNovo = $v; return $this; }
    public function getStatusNovoLabel(): string { return Solicitacao::STATUS_LABELS[$this->statusNovo] ?? $this->statusNovo; }
    public function getStatusAnteriorLabel(): ?string
    {
        return $this->statusAnterior ? (Solicitacao::STATUS_LABELS[$this->statusAnterior] ?? $this->statusAnterior) : null;
    }

    public function getNota(): ?string { return $this->nota; }
    public function setNota(?string $v): static { $this->nota = $v; return $this; }

    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }
}
