<?php

namespace App\Entity;

use App\Repository\TipoSolicitacaoConfigRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TipoSolicitacaoConfigRepository::class)]
#[ORM\Table(name: "tipo_solicitacao_config")]
class TipoSolicitacaoConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $tipo;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'tipo_solicitacao_responsaveis')]
    private Collection $responsaveisDefault;

    public function __construct()
    {
        $this->responsaveisDefault = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getTipo(): string { return $this->tipo; }
    public function setTipo(string $tipo): static { $this->tipo = $tipo; return $this; }
    public function getTipoLabel(): string { return Solicitacao::TIPOS[$this->tipo] ?? $this->tipo; }
    public function getResponsaveisDefault(): Collection { return $this->responsaveisDefault; }
    public function addResponsaveisDefault(User $u): static
    {
        if (!$this->responsaveisDefault->contains($u)) { $this->responsaveisDefault->add($u); }
        return $this;
    }
    public function removeResponsaveisDefault(User $u): static
    {
        $this->responsaveisDefault->removeElement($u);
        return $this;
    }
}
