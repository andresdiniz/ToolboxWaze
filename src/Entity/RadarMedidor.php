<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RadarMedidorRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Radar (medidor de velocidade) verificado pelo INMETRO/RBMLQ.
 *
 * Um radar pode ter várias Faixas e vários registros de Histórico.
 * Esses são armazenados em tabelas separadas (RadarFaixa e RadarHistorico).
 *
 * A chave de idempotência é o row_hash (SHA-256 do JSON completo do item).
 * Para radares manuais, row_hash é NULL até o INMETRO publicar o registro oficial.
 */
#[ORM\Entity(repositoryClass: RadarMedidorRepository::class)]
#[ORM\Table(name: 'radar_medidor')]
#[ORM\UniqueConstraint(name: 'uq_radar_row_hash',       columns: ['row_hash'])]
#[ORM\Index(name: 'idx_radar_uf',                       columns: ['sigla_uf'])]
#[ORM\Index(name: 'idx_radar_municipio',                columns: ['municipio'])]
#[ORM\Index(name: 'idx_radar_proprietario_nome',        columns: ['proprietario_nome'])]
#[ORM\Index(name: 'idx_radar_ultimo_resultado',         columns: ['ultimo_resultado'])]
#[ORM\Index(name: 'idx_radar_data_validade',            columns: ['data_validade'])]
#[ORM\Index(name: 'idx_radar_data_verificacao_efetiva', columns: ['data_verificacao_efetiva'])]
#[ORM\Index(name: 'idx_radar_origem',                   columns: ['origem'])]
class RadarMedidor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    // ----- Campos raiz do JSON -----

    #[ORM\Column(name: 'sigla_uf', type: 'string', length: 2)]
    private string $siglaUf;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $estado = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $municipio = null;

    #[ORM\Column(name: 'local_verificacao', type: 'string', length: 255, nullable: true)]
    private ?string $localVerificacao = null;

    #[ORM\Column(name: 'data_ultima_verificacao', type: 'string', length: 20, nullable: true)]
    private ?string $dataUltimaVerificacao = null;

    #[ORM\Column(name: 'data_validade', type: 'string', length: 20, nullable: true)]
    private ?string $dataValidade = null;

    #[ORM\Column(name: 'data_verificacao_efetiva', type: 'string', length: 20, nullable: true)]
    private ?string $dataVerificacaoEfetiva = null;

    #[ORM\Column(name: 'ultimo_resultado', type: 'string', length: 50, nullable: true)]
    private ?string $ultimoResultado = null;

    #[ORM\Column(name: 'tipo_medidor', type: 'string', length: 50, nullable: true)]
    private ?string $tipoMedidor = null;

    // ----- Proprietário (objeto aninhado) -----

    #[ORM\Column(name: 'proprietario_nome', type: 'string', length: 255, nullable: true)]
    private ?string $proprietarioNome = null;

    #[ORM\Column(name: 'proprietario_municipio', type: 'string', length: 150, nullable: true)]
    private ?string $proprietarioMunicipio = null;

    #[ORM\Column(name: 'proprietario_estado', type: 'string', length: 2, nullable: true)]
    private ?string $proprietarioEstado = null;

    // ----- Faixas e Histórico (armazenados como JSON e em tabelas relacionadas) -----

    #[ORM\Column(name: 'faixas_json', type: 'json', nullable: true)]
    private ?array $faixasJson = null;

    #[ORM\Column(name: 'historico_json', type: 'json', nullable: true)]
    private ?array $historicoJson = null;

    // ----- Hashes e metadados -----

    #[ORM\Column(name: 'row_hash', type: 'string', length: 64, nullable: true)]
    private ?string $rowHash = null;

    #[ORM\Column(name: 'identity_hash', type: 'string', length: 64, nullable: true)]
    private ?string $identityHash = null;

    #[ORM\Column(name: 'raw_data', type: 'json', nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column(name: 'imported_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // ----- Novos campos: origem e criador -----

    /**
     * 'inmetro' = importado automaticamente | 'manual' = criado pelo usuário.
     */
    #[ORM\Column(name: 'origem', type: 'string', length: 10, options: ['default' => 'inmetro'])]
    private string $origem = 'inmetro';

    /**
     * ID do usuário que cadastrou manualmente (NULL para registros do INMETRO).
     */
    #[ORM\Column(name: 'criado_por', type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $criadoPor = null;

    // ----- Getters -----

    public function getId(): ?int                             { return $this->id; }
    public function getSiglaUf(): string                      { return $this->siglaUf; }
    public function getEstado(): ?string                      { return $this->estado; }
    public function getMunicipio(): ?string                   { return $this->municipio; }
    public function getLocalVerificacao(): ?string            { return $this->localVerificacao; }
    public function getDataUltimaVerificacao(): ?string       { return $this->dataUltimaVerificacao; }
    public function getDataValidade(): ?string                { return $this->dataValidade; }
    public function getDataVerificacaoEfetiva(): ?string      { return $this->dataVerificacaoEfetiva; }
    public function getUltimoResultado(): ?string             { return $this->ultimoResultado; }
    public function getTipoMedidor(): ?string                 { return $this->tipoMedidor; }
    public function getProprietarioNome(): ?string            { return $this->proprietarioNome; }
    public function getProprietarioMunicipio(): ?string       { return $this->proprietarioMunicipio; }
    public function getProprietarioEstado(): ?string          { return $this->proprietarioEstado; }
    public function getFaixasJson(): ?array                   { return $this->faixasJson; }
    public function getHistoricoJson(): ?array                { return $this->historicoJson; }
    public function getRowHash(): ?string                     { return $this->rowHash; }
    public function getIdentityHash(): ?string                { return $this->identityHash; }
    public function getRawData(): ?array                      { return $this->rawData; }
    public function getImportedAt(): \DateTimeImmutable       { return $this->importedAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable       { return $this->updatedAt; }
    public function getOrigem(): string                       { return $this->origem; }
    public function getCriadoPor(): ?int                      { return $this->criadoPor; }
    public function isManual(): bool                          { return $this->origem === 'manual'; }

    // ----- Setters -----

    public function setSiglaUf(string $v): static                        { $this->siglaUf = $v; return $this; }
    public function setEstado(?string $v): static                        { $this->estado = $v; return $this; }
    public function setMunicipio(?string $v): static                     { $this->municipio = $v; return $this; }
    public function setLocalVerificacao(?string $v): static              { $this->localVerificacao = $v; return $this; }
    public function setDataUltimaVerificacao(?string $v): static         { $this->dataUltimaVerificacao = $v; return $this; }
    public function setDataValidade(?string $v): static                  { $this->dataValidade = $v; return $this; }
    public function setDataVerificacaoEfetiva(?string $v): static        { $this->dataVerificacaoEfetiva = $v; return $this; }
    public function setUltimoResultado(?string $v): static               { $this->ultimoResultado = $v; return $this; }
    public function setTipoMedidor(?string $v): static                   { $this->tipoMedidor = $v; return $this; }
    public function setProprietarioNome(?string $v): static              { $this->proprietarioNome = $v; return $this; }
    public function setProprietarioMunicipio(?string $v): static         { $this->proprietarioMunicipio = $v; return $this; }
    public function setProprietarioEstado(?string $v): static            { $this->proprietarioEstado = $v; return $this; }
    public function setFaixasJson(?array $v): static                     { $this->faixasJson = $v; return $this; }
    public function setHistoricoJson(?array $v): static                  { $this->historicoJson = $v; return $this; }
    public function setRowHash(?string $v): static                       { $this->rowHash = $v; return $this; }
    public function setIdentityHash(?string $v): static                  { $this->identityHash = $v; return $this; }
    public function setRawData(?array $v): static                        { $this->rawData = $v; return $this; }
    public function setImportedAt(\DateTimeImmutable $v): static         { $this->importedAt = $v; return $this; }
    public function setUpdatedAt(?\DateTimeImmutable $v): static         { $this->updatedAt = $v; return $this; }
    public function setOrigem(string $v): static                         { $this->origem = $v; return $this; }
    public function setCriadoPor(?int $v): static                        { $this->criadoPor = $v; return $this; }

    // ----- Helpers -----

    public function isRecente(int $days = 30): bool
    {
        if ($this->dataVerificacaoEfetiva === null) {
            return false;
        }
        $parts = explode('/', $this->dataVerificacaoEfetiva);
        if (count($parts) !== 3) {
            return false;
        }
        try {
            $data  = \DateTimeImmutable::createFromFormat('d/m/Y', $this->dataVerificacaoEfetiva);
            $limit = new \DateTimeImmutable("-{$days} days");
            $today = new \DateTimeImmutable('today');
            return $data >= $limit && $data <= $today;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Preenche apenas os campos NULL com dados vindos do INMETRO.
     * Chamado pelo importador quando encontra um radar manual com mesmo local/UF.
     */
    public function mergeFromInmetro(array $data): void
    {
        foreach ([
            'estado'                  => 'setEstado',
            'municipio'               => 'setMunicipio',
            'local_verificacao'       => 'setLocalVerificacao',
            'data_ultima_verificacao' => 'setDataUltimaVerificacao',
            'data_validade'           => 'setDataValidade',
            'ultimo_resultado'        => 'setUltimoResultado',
            'tipo_medidor'            => 'setTipoMedidor',
            'proprietario_nome'       => 'setProprietarioNome',
            'proprietario_municipio'  => 'setProprietarioMunicipio',
            'proprietario_estado'     => 'setProprietarioEstado',
        ] as $key => $setter) {
            if (!empty($data[$key]) && empty($this->$key ?? null)) {
                $this->$setter($data[$key]);
            }
        }

        // Campos que sempre vem do INMETRO mesmo que já existam
        if (!empty($data['row_hash']))     { $this->setRowHash($data['row_hash']); }
        if (!empty($data['identity_hash'])){ $this->setIdentityHash($data['identity_hash']); }
        if (!empty($data['raw_data']))     { $this->setRawData($data['raw_data']); }

        $this->setOrigem('inmetro');
        $this->setUpdatedAt(new \DateTimeImmutable());
    }
}
