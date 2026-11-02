<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EscolaInepRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EscolaInepRepository::class)]
#[ORM\Table(name: 'escola_inep')]
#[ORM\Index(name: 'idx_ei_codigo_inep',    columns: ['codigo_inep'])]
#[ORM\Index(name: 'idx_ei_uf_municipio',   columns: ['uf', 'municipio'])]
#[ORM\Index(name: 'idx_ei_row_hash',       columns: ['row_hash'])]
#[ORM\Index(name: 'idx_ei_identity_hash',  columns: ['identity_hash'])]
#[ORM\Index(name: 'idx_ei_status',         columns: ['status'])]
#[ORM\Index(name: 'idx_ei_reservado_para', columns: ['reservado_para'])]
#[ORM\Index(name: 'idx_ei_uf_status_reservado_em', columns: ['uf', 'status', 'reservado_em'])]
class EscolaInep
{
    public const STATUS_NULL           = null;
    public const STATUS_RESERVADO      = 'reservado';
    public const STATUS_CONFIRMADO     = 'confirmado';
    public const STATUS_NAO_LOCALIZADO = 'nao_localizado';
    public const STATUS_PULADO         = 'pulado';

    public const ALL_STATUS = [
        self::STATUS_RESERVADO,
        self::STATUS_CONFIRMADO,
        self::STATUS_NAO_LOCALIZADO,
        self::STATUS_PULADO,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $restricaoAtendimento = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $escola = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $codigoInep = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $uf = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $municipio = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $localizacao = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $localidadeDiferenciada = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $categoriaAdministrativa = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $endereco = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $telefone = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $dependenciaAdministrativa = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $categoriaEscolaPrivada = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $conveniada = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $regulamentacao = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $porte = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $etapasEnsino = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $outrasOfertas = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 8, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 8, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(name: 'link_waze', type: 'string', length: 1000, nullable: true)]
    private ?string $linkWaze = null;

    #[ORM\Column(name: 'permanent_hazard_id', type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $permanentHazardId = null;

    #[ORM\Column(name: 'link_area_escolar', type: 'string', length: 1000, nullable: true)]
    private ?string $linkAreaEscolar = null;

    #[ORM\Column(length: 64)]
    private string $rowHash;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $identityHash = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(name: 'reservado_para', length: 100, nullable: true)]
    private ?string $reservadoPara = null;

    #[ORM\Column(name: 'reservado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reservadoEm = null;

    /** @var Collection<int, EscolaInepComentario> */
    #[ORM\OneToMany(
        targetEntity: EscolaInepComentario::class,
        mappedBy: 'escola',
        cascade: ['persist'],
        orphanRemoval: true,
        fetch: 'EXTRA_LAZY',
    )]
    #[ORM\OrderBy(['criadoEm' => 'DESC'])]
    private Collection $comentarios;

    public function __construct()
    {
        $this->importedAt  = new \DateTimeImmutable();
        $this->comentarios = new ArrayCollection();
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

    public function getLinkWaze(): ?string { return $this->linkWaze; }
    public function getLinkAreaEscolar(): ?string { return $this->linkAreaEscolar; }
    public function getPermanentHazardId(): ?int { return $this->permanentHazardId; }

    public function setLinkWaze(?string $url): self
    {
        $this->linkWaze = $url;
        $this->permanentHazardId = $url ? self::extractPermanentHazardId($url) : null;
        return $this;
    }

    public function setLinkAreaEscolar(?string $v): self { $this->linkAreaEscolar = $v; return $this; }

    public static function extractPermanentHazardId(string $url): ?int
    {
        if (preg_match('/[?&]permanentHazards=(\d+)/', $url, $m)) {
            return (int) $m[1];
        }
        return null;
    }

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

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(?string $v): self
    {
        if ($v !== null && !in_array($v, self::ALL_STATUS, true)) {
            throw new \InvalidArgumentException('Status inválido.');
        }

        $this->status = $v;
        return $this;
    }

    public function getReservadoPara(): ?string { return $this->reservadoPara; }
    public function setReservadoPara(?string $v): self
    {
        $this->reservadoPara = $v !== null ? mb_substr(trim($v), 0, 100) : null;
        return $this;
    }

    public function getReservadoEm(): ?\DateTimeImmutable { return $this->reservadoEm; }
    public function setReservadoEm(?\DateTimeImmutable $v): self
    {
        $this->reservadoEm = $v;
        return $this;
    }

    /** @return Collection<int, EscolaInepComentario> */
    public function getComentarios(): Collection { return $this->comentarios; }
}