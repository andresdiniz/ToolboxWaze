<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RadarMedidorRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Armazena os medidores (radares) do RBMLQ/INMETRO para todos os estados.
 *
 * A chave de idempotência é o row_hash (SHA-256 de todos os campos).
 * Isso permite re-importar sem duplicar e atualizar apenas o que mudou.
 */
#[ORM\Entity(repositoryClass: RadarMedidorRepository::class)]
#[ORM\Table(name: 'radar_medidor')]
#[ORM\UniqueConstraint(name: 'uq_radar_row_hash', columns: ['row_hash'])]
#[ORM\Index(name: 'idx_radar_uf',          columns: ['uf'])]
#[ORM\Index(name: 'idx_radar_municipio',   columns: ['municipio'])]
#[ORM\Index(name: 'idx_radar_cnpj',        columns: ['cnpj_empresa'])]
#[ORM\Index(name: 'idx_radar_num_serie',   columns: ['numero_serie'])]
#[ORM\Index(name: 'idx_radar_identity',    columns: ['identity_hash'])]
class RadarMedidor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    // ----- Identificação UF (preenchida pelo handler) -----

    #[ORM\Column(type: 'string', length: 2)]
    private string $uf;

    // ----- Campos do JSON RBMLQ -----

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $municipio = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $logradouro = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $cep = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $nomeEmpresa = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $cnpjEmpresa = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $tipoMedidor = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $marcaMedidor = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $modeloMedidor = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $numeroSerie = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $capacidade = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $situacao = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $dataVerificacao = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $dataValidade = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $dataLacre = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lacre = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $numeroCertificado = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $orgaoVerificador = null;

    /** Latitude (string para preservar precisão original do JSON) */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $latitude = null;

    /** Longitude (string para preservar precisão original do JSON) */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $longitude = null;

    // ----- Hashes e metadados -----

    /** SHA-256 de todos os campos — UNIQUE KEY para upsert */
    #[ORM\Column(type: 'string', length: 64)]
    private string $rowHash;

    /** SHA-256 de: numero_serie + uf — detecta mesmo medidor entre importações */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $identityHash = null;

    /** JSON completo original do registro */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawData = null;

    /** Data da primeira importação */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $importedAt;

    /** Data da última atualização (null se nunca foi alterado após o primeiro import) */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // ----- Getters -----

    public function getId(): ?int                          { return $this->id; }
    public function getUf(): string                        { return $this->uf; }
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
    public function getDataValidade(): ?string             { return $this->dataValidade; }
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

    // ----- Setters -----

    public function setUf(string $uf): static                               { $this->uf = $uf; return $this; }
    public function setMunicipio(?string $v): static                        { $this->municipio = $v; return $this; }
    public function setLogradouro(?string $v): static                       { $this->logradouro = $v; return $this; }
    public function setCep(?string $v): static                              { $this->cep = $v; return $this; }
    public function setNomeEmpresa(?string $v): static                      { $this->nomeEmpresa = $v; return $this; }
    public function setCnpjEmpresa(?string $v): static                      { $this->cnpjEmpresa = $v; return $this; }
    public function setTipoMedidor(?string $v): static                      { $this->tipoMedidor = $v; return $this; }
    public function setMarcaMedidor(?string $v): static                     { $this->marcaMedidor = $v; return $this; }
    public function setModeloMedidor(?string $v): static                    { $this->modeloMedidor = $v; return $this; }
    public function setNumeroSerie(?string $v): static                      { $this->numeroSerie = $v; return $this; }
    public function setCapacidade(?string $v): static                       { $this->capacidade = $v; return $this; }
    public function setSituacao(?string $v): static                         { $this->situacao = $v; return $this; }
    public function setDataVerificacao(?string $v): static                  { $this->dataVerificacao = $v; return $this; }
    public function setDataValidade(?string $v): static                     { $this->dataValidade = $v; return $this; }
    public function setDataLacre(?string $v): static                        { $this->dataLacre = $v; return $this; }
    public function setLacre(?string $v): static                            { $this->lacre = $v; return $this; }
    public function setNumeroCertificado(?string $v): static                { $this->numeroCertificado = $v; return $this; }
    public function setOrgaoVerificador(?string $v): static                 { $this->orgaoVerificador = $v; return $this; }
    public function setLatitude(?string $v): static                         { $this->latitude = $v; return $this; }
    public function setLongitude(?string $v): static                        { $this->longitude = $v; return $this; }
    public function setRowHash(string $v): static                           { $this->rowHash = $v; return $this; }
    public function setIdentityHash(?string $v): static                     { $this->identityHash = $v; return $this; }
    public function setRawData(?array $v): static                           { $this->rawData = $v; return $this; }
    public function setImportedAt(\DateTimeImmutable $v): static            { $this->importedAt = $v; return $this; }
    public function setUpdatedAt(?\DateTimeImmutable $v): static            { $this->updatedAt = $v; return $this; }
}
