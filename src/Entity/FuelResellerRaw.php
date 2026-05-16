<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FuelResellerRawRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FuelResellerRawRepository::class)]
#[ORM\Table(name: 'fuel_reseller_raw')]
#[ORM\Index(name: 'idx_frr_cnpj', columns: ['cnpj'])]
#[ORM\Index(name: 'idx_frr_uf_municipio', columns: ['uf', 'municipio'])]
#[ORM\Index(name: 'idx_frr_row_hash', columns: ['row_hash'])]
class FuelResellerRaw
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $codigoIsimp = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $autorizacao = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $dataPublicacao = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $razaoSocial = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cnpj = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $endereco = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complemento = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bairro = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cep = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $uf = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $municipio = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bandeira = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $dataVinculacao = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomeFantasia = null;

    #[ORM\Column(length: 64)]
    private string $rowHash;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $identityHash = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    /**
     * Data da última atualização do registro (quando o dado do posto mudou).
     * NULL = nunca atualizado após o primeiro import.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCodigoIsimp(): ?string { return $this->codigoIsimp; }
    public function setCodigoIsimp(?string $value): self { $this->codigoIsimp = $value; return $this; }

    public function getAutorizacao(): ?string { return $this->autorizacao; }
    public function setAutorizacao(?string $value): self { $this->autorizacao = $value; return $this; }

    public function getDataPublicacao(): ?string { return $this->dataPublicacao; }
    public function setDataPublicacao(?string $value): self { $this->dataPublicacao = $value; return $this; }

    public function getRazaoSocial(): ?string { return $this->razaoSocial; }
    public function setRazaoSocial(?string $value): self { $this->razaoSocial = $value; return $this; }

    public function getCnpj(): ?string { return $this->cnpj; }
    public function setCnpj(?string $value): self { $this->cnpj = $value; return $this; }

    public function getEndereco(): ?string { return $this->endereco; }
    public function setEndereco(?string $value): self { $this->endereco = $value; return $this; }

    public function getComplemento(): ?string { return $this->complemento; }
    public function setComplemento(?string $value): self { $this->complemento = $value; return $this; }

    public function getBairro(): ?string { return $this->bairro; }
    public function setBairro(?string $value): self { $this->bairro = $value; return $this; }

    public function getCep(): ?string { return $this->cep; }
    public function setCep(?string $value): self { $this->cep = $value; return $this; }

    public function getUf(): ?string { return $this->uf; }
    public function setUf(?string $value): self { $this->uf = $value; return $this; }

    public function getMunicipio(): ?string { return $this->municipio; }
    public function setMunicipio(?string $value): self { $this->municipio = $value; return $this; }

    public function getBandeira(): ?string { return $this->bandeira; }
    public function setBandeira(?string $value): self { $this->bandeira = $value; return $this; }

    public function getDataVinculacao(): ?string { return $this->dataVinculacao; }
    public function setDataVinculacao(?string $value): self { $this->dataVinculacao = $value; return $this; }

    public function getNomeFantasia(): ?string { return $this->nomeFantasia; }
    public function setNomeFantasia(?string $value): self { $this->nomeFantasia = $value; return $this; }

    public function getRowHash(): string { return $this->rowHash; }
    public function setRowHash(string $value): self { $this->rowHash = $value; return $this; }

    public function getIdentityHash(): ?string { return $this->identityHash; }
    public function setIdentityHash(?string $value): self { $this->identityHash = $value; return $this; }

    public function getRawData(): ?array { return $this->rawData; }
    public function setRawData(?array $value): self { $this->rawData = $value; return $this; }

    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }
    public function setImportedAt(\DateTimeImmutable $value): self { $this->importedAt = $value; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $value): self { $this->updatedAt = $value; return $this; }
}
