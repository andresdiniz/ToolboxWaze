<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RadarMedidorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Medidor de velocidade RBMLQ/INMETRO.
 *
 * Tabela: radar_medidor
 * Colunas incluem todos os campos das migrations:
 *   - Version20260516130000 : criação da tabela
 *   - Version20260517...    : sigla_uf, data_ultima_verificacao, inserted_by
 *   - Version20260522...    : data_verificacao_efetiva
 *   - Version20260524...    : link_waze
 */
#[ORM\Entity(repositoryClass: RadarMedidorRepository::class)]
#[ORM\Table(name: 'radar_medidor')]
#[ORM\Index(name: 'idx_radar_uf',                         columns: ['uf'])]
#[ORM\Index(name: 'idx_radar_municipio',                  columns: ['municipio'])]
#[ORM\Index(name: 'idx_radar_cnpj',                       columns: ['cnpj_empresa'])]
#[ORM\Index(name: 'idx_radar_num_serie',                  columns: ['numero_serie'])]
#[ORM\Index(name: 'idx_radar_identity',                   columns: ['identity_hash'])]
#[ORM\Index(name: 'idx_radar_data_verificacao_efetiva',   columns: ['data_verificacao_efetiva'])]
class RadarMedidor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
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
     * Data de verificação efetiva (calculada na importação):
     *   1. data_ultima_verificacao se preenchida
     *   2. data_validade - 1 ano
     *   3. NULL
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
     * URL do radar no Waze (aba REFERENCIA.UF da planilha Google Sheets).
     * Preenchida pelo app:import-radares etapa 2.
     */
    #[ORM\Column(name: 'link_waze', type: 'string', length: 500, nullable: true)]
    private ?string $linkWaze = null;

    /** @var Collection<int, RadarFaixa> */
    #[ORM\OneToMany(targetEntity: RadarFaixa::class, mappedBy: 'radarMedidor', cascade: ['persist', 'remove'])]
    private Collection $faixas;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
        $this->faixas     = new ArrayCollection();
    }

    // ── Getters ──────────────────────────────────────────────────────────────

    public function getId(): ?int                          { return $this->id; }
    public function getUf(): string                        { return $this->uf; }
    public function getSiglaUf(): ?string                  { return $this->siglaUf; }
    public function getMunicipio(): ?string                { return $this->municipio; }
    public function getLogradouro(): ?string               { return $this->logradouro; }
    public function getCep(): ?string                      { return $this->cep; }
    public function getNomeEmpresa(): ?string              { return $this->nomeEmpresa; }
    public function getCnpjEmpresa(): ?string              { return $this->cnpjEmpresa; }
    public function getTipoMedidor(): ?string              { return $this->tipoMedidor; }
    public function getMarcaMedidor(): ?string             { return $this->marcaMedidor; }
    public function getModeloMedidor(): ?string            { return $this->modeloMedidor; }
    public function getNumeroSerie(): ?string              { return $this->numeroSerie; }
    public function getCapacidade(): ?string               { return $this->capacidade; }
    public function getSituacao(): ?string                 { return $this->situacao; }
    public function getDataVerificacao(): ?string          { return $this->dataVerificacao; }
    public function getDataUltimaVerificacao(): ?string    { return $this->dataUltimaVerificacao; }
    public function getDataValidade(): ?string             { return $this->dataValidade; }
    public function getDataVerificacaoEfetiva(): ?string   { return $this->dataVerificacaoEfetiva; }
    public function getDataLacre(): ?string                { return $this->dataLacre; }
    public function getLacre(): ?string                    { return $this->lacre; }
    public function getNumeroCertificado(): ?string        { return $this->numeroCertificado; }
    public function getOrgaoVerificador(): ?string         { return $this->orgaoVerificador; }
    public function getLatitude(): ?string                 { return $this->latitude; }
    public function getLongitude(): ?string                { return $this->longitude; }
    public function getRowHash(): string                   { return $this->rowHash; }
    public function getIdentityHash(): ?string             { return $this->identityHash; }
    public function getRawData(): ?array                   { return $this->rawData; }
    public function getImportedAt(): \DateTimeImmutable    { return $this->importedAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable    { return $this->updatedAt; }
    public function getInsertedBy(): ?string               { return $this->insertedBy; }
    public function getLinkWaze(): ?string                 { return $this->linkWaze; }

    /** @return Collection<int, RadarFaixa> */
    public function getFaixas(): Collection { return $this->faixas; }

    // ── Setters ──────────────────────────────────────────────────────────────

    public function setUf(string $v): static                           { $this->uf = strtoupper($v); return $this; }
    public function setSiglaUf(?string $v): static                     { $this->siglaUf = $v ? strtoupper($v) : null; return $this; }
    public function setMunicipio(?string $v): static                   { $this->municipio = $v; return $this; }
    public function setLogradouro(?string $v): static                  { $this->logradouro = $v; return $this; }
    public function setCep(?string $v): static                         { $this->cep = $v; return $this; }
    public function setNomeEmpresa(?string $v): static                 { $this->nomeEmpresa = $v; return $this; }
    public function setCnpjEmpresa(?string $v): static                 { $this->cnpjEmpresa = $v; return $this; }
    public function setTipoMedidor(?string $v): static                 { $this->tipoMedidor = $v; return $this; }
    public function setMarcaMedidor(?string $v): static                { $this->marcaMedidor = $v; return $this; }
    public function setModeloMedidor(?string $v): static               { $this->modeloMedidor = $v; return $this; }
    public function setNumeroSerie(?string $v): static                 { $this->numeroSerie = $v; return $this; }
    public function setCapacidade(?string $v): static                  { $this->capacidade = $v; return $this; }
    public function setSituacao(?string $v): static                    { $this->situacao = $v; return $this; }
    public function setDataVerificacao(?string $v): static             { $this->dataVerificacao = $v; return $this; }
    public function setDataUltimaVerificacao(?string $v): static       { $this->dataUltimaVerificacao = $v; return $this; }
    public function setDataValidade(?string $v): static                { $this->dataValidade = $v; return $this; }
    public function setDataVerificacaoEfetiva(?string $v): static      { $this->dataVerificacaoEfetiva = $v; return $this; }
    public function setDataLacre(?string $v): static                   { $this->dataLacre = $v; return $this; }
    public function setLacre(?string $v): static                       { $this->lacre = $v; return $this; }
    public function setNumeroCertificado(?string $v): static           { $this->numeroCertificado = $v; return $this; }
    public function setOrgaoVerificador(?string $v): static            { $this->orgaoVerificador = $v; return $this; }
    public function setLatitude(?string $v): static                    { $this->latitude = $v; return $this; }
    public function setLongitude(?string $v): static                   { $this->longitude = $v; return $this; }
    public function setRowHash(string $v): static                      { $this->rowHash = $v; return $this; }
    public function setIdentityHash(?string $v): static                { $this->identityHash = $v; return $this; }
    public function setRawData(?array $v): static                      { $this->rawData = $v; return $this; }
    public function setImportedAt(\DateTimeImmutable $v): static       { $this->importedAt = $v; return $this; }
    public function setUpdatedAt(?\DateTimeImmutable $v): static       { $this->updatedAt = $v; return $this; }
    public function setInsertedBy(?string $v): static                  { $this->insertedBy = $v; return $this; }
    public function setLinkWaze(?string $v): static                    { $this->linkWaze = $v ?: null; return $this; }

    public function __toString(): string
    {
        return sprintf('%s — %s (%s)', $this->uf, $this->municipio ?? '?', $this->tipoMedidor ?? '?');
    }
}
