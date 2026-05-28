<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EscolaInepRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Censo Escolar INEP — planilha pública Google Sheets (todos os estados).
 * Colunas mapeadas a partir do cabeçalho do CSV publicado.
 */
#[ORM\Entity(repositoryClass: EscolaInepRepository::class)]
#[ORM\Table(name: 'escola_inep')]
#[ORM\Index(name: 'idx_ei_codigo_inep',    columns: ['codigo_inep'])]
#[ORM\Index(name: 'idx_ei_uf_municipio',   columns: ['uf', 'municipio'])]
#[ORM\Index(name: 'idx_ei_row_hash',       columns: ['row_hash'])]
#[ORM\Index(name: 'idx_ei_identity_hash',  columns: ['identity_hash'])]
class EscolaInep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    /** Restrição de Atendimento */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $restricaoAtendimento = null;

    /** Escola (nome) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $escola = null;

    /** Código INEP */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $codigoInep = null;

    /** UF (sigla) */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $uf = null;

    /** Município */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $municipio = null;

    /** Localização (Urbana / Rural) */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $localizacao = null;

    /** Localidade Diferenciada */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $localidadeDiferenciada = null;

    /** Categoria Administrativa (Pública / Privada) */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $categoriaAdministrativa = null;

    /** Endereço completo */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $endereco = null;

    /** Telefone */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $telefone = null;

    /** Dependência Administrativa (Federal / Estadual / Municipal / Privada) */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $dependenciaAdministrativa = null;

    /** Categoria Escola Privada */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $categoriaEscolaPrivada = null;

    /** Conveniada Poder Público (Sim/Não) */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $conveniada = null;

    /** Regulamentação pelo Conselho de Educação */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $regulamentacao = null;

    /** Porte da Escola */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $porte = null;

    /** Etapas e Modalidade de Ensino Oferecidas */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $etapasEnsino = null;

    /** Outras Ofertas Educacionais */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $outrasOfertas = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 8, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 8, nullable: true)]
    private ?string $longitude = null;

    /** SHA-256 de toda a linha — detecta qualquer alteração */
    #[ORM\Column(length: 64)]
    private string $rowHash;

    /** SHA-256 do Código INEP — identidade imutável do registro */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $identityHash = null;

    /** JSON bruto da linha original */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRestricaoAtendimento(): ?string { return $this->restricaoAtendimento; }
    public function setRestricaoAtendimento(?string $v): self { $this->restricaoAtendimento = $v; return $this; }

    public function getEscola(): ?string { return $this->escola; }
    public function setEscola(?string $v): self { $this->escola = $v; return $this; }

    public function getCodigoInep(): ?string { return $this->codigoInep; }
    public function setCodigoInep(?string $v): self { $this->codigoInep = $v; return $this; }

    public function getUf(): ?string { return $this->uf; }
    public function setUf(?string $v): self { $this->uf = $v; return $this; }

    public function getMunicipio(): ?string { return $this->municipio; }
    public function setMunicipio(?string $v): self { $this->municipio = $v; return $this; }

    public function getLocalizacao(): ?string { return $this->localizacao; }
    public function setLocalizacao(?string $v): self { $this->localizacao = $v; return $this; }

    public function getLocalidadeDiferenciada(): ?string { return $this->localidadeDiferenciada; }
    public function setLocalidadeDiferenciada(?string $v): self { $this->localidadeDiferenciada = $v; return $this; }

    public function getCategoriaAdministrativa(): ?string { return $this->categoriaAdministrativa; }
    public function setCategoriaAdministrativa(?string $v): self { $this->categoriaAdministrativa = $v; return $this; }

    public function getEndereco(): ?string { return $this->endereco; }
    public function setEndereco(?string $v): self { $this->endereco = $v; return $this; }

    public function getTelefone(): ?string { return $this->telefone; }
    public function setTelefone(?string $v): self { $this->telefone = $v; return $this; }

    public function getDependenciaAdministrativa(): ?string { return $this->dependenciaAdministrativa; }
    public function setDependenciaAdministrativa(?string $v): self { $this->dependenciaAdministrativa = $v; return $this; }

    public function getCategoriaEscolaPrivada(): ?string { return $this->categoriaEscolaPrivada; }
    public function setCategoriaEscolaPrivada(?string $v): self { $this->categoriaEscolaPrivada = $v; return $this; }

    public function getConveniada(): ?string { return $this->conveniada; }
    public function setConveniada(?string $v): self { $this->conveniada = $v; return $this; }

    public function getRegulamentacao(): ?string { return $this->regulamentacao; }
    public function setRegulamentacao(?string $v): self { $this->regulamentacao = $v; return $this; }

    public function getPorte(): ?string { return $this->porte; }
    public function setPorte(?string $v): self { $this->porte = $v; return $this; }

    public function getEtapasEnsino(): ?string { return $this->etapasEnsino; }
    public function setEtapasEnsino(?string $v): self { $this->etapasEnsino = $v; return $this; }

    public function getOutrasOfertas(): ?string { return $this->outrasOfertas; }
    public function setOutrasOfertas(?string $v): self { $this->outrasOfertas = $v; return $this; }

    public function getLatitude(): ?string { return $this->latitude; }
    public function setLatitude(?string $v): self { $this->latitude = $v; return $this; }

    public function getLongitude(): ?string { return $this->longitude; }
    public function setLongitude(?string $v): self { $this->longitude = $v; return $this; }

    public function getRowHash(): string { return $this->rowHash; }
    public function setRowHash(string $v): self { $this->rowHash = $v; return $this; }

    public function getIdentityHash(): ?string { return $this->identityHash; }
    public function setIdentityHash(?string $v): self { $this->identityHash = $v; return $this; }

    public function getRawData(): ?array { return $this->rawData; }
    public function setRawData(?array $v): self { $this->rawData = $v; return $this; }

    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }
    public function setImportedAt(\DateTimeImmutable $v): self { $this->importedAt = $v; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $v): self { $this->updatedAt = $v; return $this; }
}
