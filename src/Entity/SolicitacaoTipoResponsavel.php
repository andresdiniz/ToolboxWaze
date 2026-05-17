<?php

namespace App\Entity;

use App\Repository\SolicitacaoTipoResponsavelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Armazena quais usuarios sao responsaveis por cada tipo de solicitacao.
 * Uma linha por tipo (imagem_satelite, oops, nivel, etc.).
 */
#[ORM\Entity(repositoryClass: SolicitacaoTipoResponsavelRepository::class)]
#[ORM\Table(name: 'solicitacao_tipo_responsavel')]
class SolicitacaoTipoResponsavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Valor do tipo: imagem_satelite, oops, nivel ... */
    #[ORM\Column(length: 64, unique: true)]
    private string $tipo;

    #[ORM\ManyToMany(targetEntity: User::class, fetch: 'EAGER')]
    #[ORM\JoinTable(name: 'sol_tipo_resp_users')]
    private Collection $responsaveis;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadaEm;

    public function __construct(string $tipo)
    {
        $this->tipo          = $tipo;
        $this->responsaveis  = new ArrayCollection();
        $this->atualizadaEm  = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTipo(): string { return $this->tipo; }

    public function getResponsaveis(): Collection { return $this->responsaveis; }

    public function setResponsaveis(Collection $c): static
    {
        $this->responsaveis = $c;
        return $this;
    }

    public function addResponsavel(User $u): static
    {
        if (!$this->responsaveis->contains($u)) {
            $this->responsaveis->add($u);
        }
        return $this;
    }

    public function removeResponsavel(User $u): static
    {
        $this->responsaveis->removeElement($u);
        return $this;
    }

    public function getAtualizadaEm(): \DateTimeImmutable { return $this->atualizadaEm; }

    public function touch(): static
    {
        $this->atualizadaEm = new \DateTimeImmutable();
        return $this;
    }
}
