<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RadarMedidorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RadarMedidorRepository::class)]
#[ORM\Table(name: 'radar_medidor')]
class RadarMedidor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Sigla do estado (UF) */
    #[ORM\Column(name: 'uf', type: 'string', length: 2)]
    private string $uf = '';

    /** Alias legível — mesmo valor que $uf, mantido por retrocompatibilidade */
    #[ORM\Column(name: 'sigla_uf', type: 'string', length: 2, nullable: true)]
    private ?string $siglaUf = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $municipio = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $logradouro = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $cep = null;

    #[ORM\Column(name: 'nome_empresa', type: 'string', length: 255, nullable: true)]
    private ?string $nomeEmpresa = null;

    #[ORM\Column(name: 'cnpj_empresa', type: 'string', length: 20, nullable: true)]
    private ?string $cnpjEmpresa = null;

    #[ORM\Column(name: 'tipo_medidor', type: 'string', length: 100, nullable: true)]
    private ?string $tipoMedidor = null;

    #[ORM\Column(name: 'marca_medidor', type: 'string', length: 100, nullable: true)]
    private ?string $marcaMedidor = null;

    #[ORM\Column(name: 'modelo_medidor', type: 'string', length: 100, nullable: true)]
    private ?string $modeloMedidor = null;

    #[ORM\Column(name: 'numero_serie', type: 'string', length: 100, nullable: true)]
    private ?string $numeroSerie = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $capacidade = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $situacao = null;

    #[ORM\Column(name: 'data_verificacao', type: 'string', length: 20, nullable: true)]
    private ?string $dataVerificacao = null;

    #[ORM\Column(name: 'data_ultima_verificacao', type: 'string', length: 20, nullable: true)]
    private ?string $dataUltimaVerificacao = null;

    #[ORM\Column(name: 'data_validade', type: 'string', length: 20, nullable: true)]
    private ?string $dataValidade = null;

    /**
     * Data de validade convertida para DATE.
     * Mantida separadamente para filtros e comparações no banco.
     */
    #[ORM\Column(name: 'data_validade_date', type: 'date', nullable: true)]
    private ?\DateTimeInterface $dataValidadeDate = null;

    /**
     * Data de verificação efetiva (calculada na importação):
     * 1. data_ultima_verificacao se preenchida
     * 2. data_validade - 1 ano
     * 3. NULL
     */
    #[ORM\Column(name: 'data_verificacao_efetiva', type: 'string', length: 20, nullable: true)]
    private ?string $dataVerificacaoEfetiva = null;

    #[ORM\Column(name: 'data_lacre', type: 'string', length: 20, nullable: true)]
    private ?string $dataLacre = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lacre = null;

    #[ORM\Column(name: 'numero_certificado', type: 'string', length: 100, nullable: true)]
    private ?string $numeroCertificado = null;

    #[ORM\Column(name: 'orgao_verificador', type: 'string', length: 100, nullable: true)]
    private ?string $orgaoVerificador = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(name: 'row_hash', type: 'string', length: 64)]
    private string $rowHash = '';

    #[ORM\Column(name: 'identity_hash', type: 'string', length: 64, nullable: true)]
    private ?string $identityHash = null;

    #[ORM\Column(name: 'raw_data', type: 'json', nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column(name: 'imported_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** Usuário que inseriu o registro (NULL = importação automática) */
    #[ORM\Column(name: 'inserted_by', type: 'string', length: 100, nullable: true)]
    private ?string $insertedBy = null;

    /**
     * URL do radar no Waze.
     */
    #[ORM\Column(name: 'link_waze', type: 'string', length: 500, nullable: true)]
    private ?string $linkWaze = null;

    /**
     * ID do radar que absorveu este registro.
     * NULL significa que este radar não foi mesclado.
     */
    #[ORM\Column(name: 'merged_into_id', type: 'bigint', nullable: true, options: ['unsigned' => true])]
    private ?int $mergedIntoId = null;

    /**
     * Data/hora em que este radar foi mesclado.
     */
    #[ORM\Column(name: 'merged_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $mergedAt = null;

    /**
     * Usuário que realizou a mesclagem.
     */
    #[ORM\Column(name: 'merged_by', type: 'string', length: 180, nullable: true)]
    private ?string $mergedBy = null;

    /** @var Collection<int, RadarFaixa> */
    #[ORM\OneToMany(
        targetEntity: RadarFaixa::class,
        mappedBy: 'radarMedidor',
        cascade: ['persist', 'remove']
    )]
    private Collection $faixas;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
        $this->faixas = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUf(): string
    {
        return $this->uf;
    }

    public function setUf(string $uf): static
    {
        $this->uf = strtoupper($uf);

        return $this;
    }

    public function getSiglaUf(): ?string
    {
        return $this->siglaUf;
    }

    public function setSiglaUf(?string $siglaUf): static
    {
        $this->siglaUf = $siglaUf !== null ? strtoupper($siglaUf) : null;

        return $this;
    }

    public function getMunicipio(): ?string
    {
        return $this->municipio;
    }

    public function setMunicipio(?string $municipio): static
    {
        $this->municipio = $municipio;

        return $this;
    }

    public function getLogradouro(): ?string
    {
        return $this->logradouro;
    }

    public function setLogradouro(?string $logradouro): static
    {
        $this->logradouro = $logradouro;

        return $this;
    }

    public function getCep(): ?string
    {
        return $this->cep;
    }

    public function setCep(?string $cep): static
    {
        $this->cep = $cep;

        return $this;
    }

    public function getNomeEmpresa(): ?string
    {
        return $this->nomeEmpresa;
    }

    public function setNomeEmpresa(?string $nomeEmpresa): static
    {
        $this->nomeEmpresa = $nomeEmpresa;

        return $this;
    }

    public function getCnpjEmpresa(): ?string
    {
        return $this->cnpjEmpresa;
    }

    public function setCnpjEmpresa(?string $cnpjEmpresa): static
    {
        $this->cnpjEmpresa = $cnpjEmpresa;

        return $this;
    }

    public function getTipoMedidor(): ?string
    {
        return $this->tipoMedidor;
    }

    public function setTipoMedidor(?string $tipoMedidor): static
    {
        $this->tipoMedidor = $tipoMedidor;

        return $this;
    }

    public function getMarcaMedidor(): ?string
    {
        return $this->marcaMedidor;
    }

    public function setMarcaMedidor(?string $marcaMedidor): static
    {
        $this->marcaMedidor = $marcaMedidor;

        return $this;
    }

    public function getModeloMedidor(): ?string
    {
        return $this->modeloMedidor;
    }

    public function setModeloMedidor(?string $modeloMedidor): static
    {
        $this->modeloMedidor = $modeloMedidor;

        return $this;
    }

    public function getNumeroSerie(): ?string
    {
        return $this->numeroSerie;
    }

    public function setNumeroSerie(?string $numeroSerie): static
    {
        $this->numeroSerie = $numeroSerie;

        return $this;
    }

    public function getCapacidade(): ?string
    {
        return $this->capacidade;
    }

    public function setCapacidade(?string $capacidade): static
    {
        $this->capacidade = $capacidade;

        return $this;
    }

    public function getSituacao(): ?string
    {
        return $this->situacao;
    }

    public function setSituacao(?string $situacao): static
    {
        $this->situacao = $situacao;

        return $this;
    }

    public function getDataVerificacao(): ?string
    {
        return $this->dataVerificacao;
    }

    public function setDataVerificacao(?string $dataVerificacao): static
    {
        $this->dataVerificacao = $dataVerificacao;

        return $this;
    }

    public function getDataUltimaVerificacao(): ?string
    {
        return $this->dataUltimaVerificacao;
    }

    public function setDataUltimaVerificacao(?string $dataUltimaVerificacao): static
    {
        $this->dataUltimaVerificacao = $dataUltimaVerificacao;

        return $this;
    }

    public function getDataValidade(): ?string
    {
        return $this->dataValidade;
    }

    public function setDataValidade(?string $dataValidade): static
    {
        $this->dataValidade = $dataValidade;

        return $this;
    }

    public function getDataValidadeDate(): ?\DateTimeInterface
    {
        return $this->dataValidadeDate;
    }

    public function setDataValidadeDate(?\DateTimeInterface $dataValidadeDate): static
    {
        $this->dataValidadeDate = $dataValidadeDate;

        return $this;
    }

    public function getDataVerificacaoEfetiva(): ?string
    {
        return $this->dataVerificacaoEfetiva;
    }

    public function setDataVerificacaoEfetiva(?string $dataVerificacaoEfetiva): static
    {
        $this->dataVerificacaoEfetiva = $dataVerificacaoEfetiva;

        return $this;
    }

    public function getDataLacre(): ?string
    {
        return $this->dataLacre;
    }

    public function setDataLacre(?string $dataLacre): static
    {
        $this->dataLacre = $dataLacre;

        return $this;
    }

    public function getLacre(): ?string
    {
        return $this->lacre;
    }

    public function setLacre(?string $lacre): static
    {
        $this->lacre = $lacre;

        return $this;
    }

    public function getNumeroCertificado(): ?string
    {
        return $this->numeroCertificado;
    }

    public function setNumeroCertificado(?string $numeroCertificado): static
    {
        $this->numeroCertificado = $numeroCertificado;

        return $this;
    }

    public function getOrgaoVerificador(): ?string
    {
        return $this->orgaoVerificador;
    }

    public function setOrgaoVerificador(?string $orgaoVerificador): static
    {
        $this->orgaoVerificador = $orgaoVerificador;

        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(?string $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(?string $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getRowHash(): string
    {
        return $this->rowHash;
    }

    public function setRowHash(string $rowHash): static
    {
        $this->rowHash = $rowHash;

        return $this;
    }

    public function getIdentityHash(): ?string
    {
        return $this->identityHash;
    }

    public function setIdentityHash(?string $identityHash): static
    {
        $this->identityHash = $identityHash;

        return $this;
    }

    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    public function setRawData(?array $rawData): static
    {
        $this->rawData = $rawData;

        return $this;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function setImportedAt(\DateTimeImmutable $importedAt): static
    {
        $this->importedAt = $importedAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getInsertedBy(): ?string
    {
        return $this->insertedBy;
    }

    public function setInsertedBy(?string $insertedBy): static
    {
        $this->insertedBy = $insertedBy;

        return $this;
    }

    public function getLinkWaze(): ?string
    {
        return $this->linkWaze;
    }

    public function setLinkWaze(?string $linkWaze): static
    {
        $this->linkWaze = $linkWaze ?: null;

        return $this;
    }

    public function getMergedIntoId(): ?int
    {
        return $this->mergedIntoId;
    }

    public function setMergedIntoId(?int $mergedIntoId): static
    {
        $this->mergedIntoId = $mergedIntoId;

        return $this;
    }

    public function isMerged(): bool
    {
        return $this->mergedIntoId !== null;
    }

    public function getMergedAt(): ?\DateTimeImmutable
    {
        return $this->mergedAt;
    }

    public function setMergedAt(?\DateTimeImmutable $mergedAt): static
    {
        $this->mergedAt = $mergedAt;

        return $this;
    }

    public function getMergedBy(): ?string
    {
        return $this->mergedBy;
    }

    public function setMergedBy(?string $mergedBy): static
    {
        $this->mergedBy = $mergedBy;

        return $this;
    }

    /** @return Collection<int, RadarFaixa> */
    public function getFaixas(): Collection
    {
        return $this->faixas;
    }

    public function addFaixa(RadarFaixa $faixa): static
    {
        if (!$this->faixas->contains($faixa)) {
            $this->faixas->add($faixa);
            $faixa->setRadarMedidor($this);
        }

        return $this;
    }

    public function removeFaixa(RadarFaixa $faixa): static
    {
        if ($this->faixas->removeElement($faixa)) {
            if ($faixa->getRadarMedidor() === $this) {
                $faixa->setRadarMedidor(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s — %s (%s)',
            $this->uf,
            $this->municipio ?? '?',
            $this->tipoMedidor ?? '?'
        );
    }
}