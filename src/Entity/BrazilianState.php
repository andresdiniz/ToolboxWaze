<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrazilianStateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tabela de estados brasileiros com sigla e nome.
 * Usada pelo handler de radares para iterar os 27 estados.
 */
#[ORM\Entity(repositoryClass: BrazilianStateRepository::class)]
#[ORM\Table(name: 'brazilian_state')]
class BrazilianState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Sigla UF: AC, AL, AM ... SP */
    #[ORM\Column(type: 'string', length: 2, unique: true)]
    private string $uf;

    /** Nome completo: Acre, Alagoas ... São Paulo */
    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    /** Região geográfica */
    #[ORM\Column(type: 'string', length: 20)]
    private string $region;

    public function __construct(string $uf, string $name, string $region)
    {
        $this->uf     = strtoupper($uf);
        $this->name   = $name;
        $this->region = $region;
    }

    public function getId(): ?int     { return $this->id; }
    public function getUf(): string   { return $this->uf; }
    public function getName(): string { return $this->name; }
    public function getRegion(): string { return $this->region; }

    public function setUf(string $uf): static     { $this->uf = strtoupper($uf); return $this; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function setRegion(string $region): static { $this->region = $region; return $this; }

    public function __toString(): string { return $this->uf . ' — ' . $this->name; }
}
