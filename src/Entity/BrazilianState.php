<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrazilianStateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tabela de estados brasileiros com sigla, nome e link de importação RBMLQ.
 *
 * O campo link_base_radares armazena a URL completa de download CSV por estado.
 * Quando nulo, o ImportRadarGoogleSheetsHandler usa a BASE_URL padrão da planilha.
 *
 * O nome do índice único é fixado em UNIQ_645199D6B7405B21 para evitar
 * que o Doctrine tente executar RENAME INDEX (não suportado no MariaDB < 10.5).
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
     * URL completa de download CSV dos radares RBMLQ para este estado.
     *
     * Exemplos:
     *   Google Sheets: https://docs.google.com/spreadsheets/d/e/.../pub?output=csv&gid=750233625
     *   INMETRO direto: https://www.inmetro.gov.br/rbmlq/...?uf=MG
     *
     * Quando NULL, o ImportRadarGoogleSheetsHandler usa o BASE_URL padrão
     * com o gid do UF_GID_MAP (comportamento anterior).
     */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $linkBaseRadares = null;

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

    public function getLinkBaseRadares(): ?string { return $this->linkBaseRadares; }

    public function setUf(string $uf): static     { $this->uf = strtoupper($uf); return $this; }
    public function setName(string $n): static    { $this->name = $n; return $this; }
    public function setRegion(string $r): static  { $this->region = $r; return $this; }

    public function setLinkBaseRadares(?string $url): static
    {
        $this->linkBaseRadares = $url !== '' ? $url : null;
        return $this;
    }

    public function __toString(): string { return $this->uf . ' — ' . $this->name; }
}
