<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrazilianStateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tabela de estados brasileiros com sigla, nome e links de importação.
 *
 * O campo link_referencia_radares armazena a URL da aba REFERENCIA.UF
 * (planilha Google Sheets) usada para importar os links Waze cruzando
 * pelo Nº de Série na Etapa 2 do app:import-radares.
 *
 * Quando NULL, a etapa 2 é pulada para este estado.
 */
#[ORM\Entity(repositoryClass: BrazilianStateRepository::class)]
#[ORM\Table(name: 'brazilian_state')]
#[ORM\UniqueConstraint(name: 'UNIQ_645199D6B7405B21', columns: ['uf'])]
class BrazilianState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Sigla UF: AC, AL, AM ... SP */
    #[ORM\Column(type: 'string', length: 2, unique: false)]
    private string $uf;

    /** Nome completo: Acre, Alagoas ... São Paulo */
    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    /** Região geográfica */
    #[ORM\Column(type: 'string', length: 20)]
    private string $region;

    /**
     * URL completa da aba REFERENCIA.UF (Google Sheets CSV).
     *
     * Usada na Etapa 2 do app:import-radares para importar os links Waze.
     * Cruzamento: REFERENCIA.Nº DE SÉRIE = radar_faixa.numero_serie
     *             → grava link_waze em radar_medidor.
     *
     * Exemplo:
     *   https://docs.google.com/spreadsheets/d/e/.../pub?output=csv&gid=123456789
     *
     * Quando NULL, a etapa 2 é pulada para este estado.
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $linkReferenciaRadares = null;

    public function __construct(string $uf, string $name, string $region)
    {
        $this->uf     = strtoupper($uf);
        $this->name   = $name;
        $this->region = $region;
    }

    public function getId(): ?int       { return $this->id; }
    public function getUf(): string     { return $this->uf; }
    public function getName(): string   { return $this->name; }
    public function getRegion(): string { return $this->region; }

    public function getLinkReferenciaRadares(): ?string { return $this->linkReferenciaRadares; }

    public function setUf(string $uf): static    { $this->uf = strtoupper($uf); return $this; }
    public function setName(string $n): static   { $this->name = $n; return $this; }
    public function setRegion(string $r): static { $this->region = $r; return $this; }

    public function setLinkReferenciaRadares(?string $url): static
    {
        $this->linkReferenciaRadares = ($url !== '' && $url !== null) ? $url : null;
        return $this;
    }

    public function __toString(): string { return $this->uf . ' — ' . $this->name; }
}
