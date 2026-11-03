<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EstabelecimentoSaudeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EstabelecimentoSaudeRepository::class)]
#[ORM\Table(name: 'estabelecimento_saude')]
#[ORM\UniqueConstraint(name: 'uniq_es_cnes', columns: ['cnes'])]
#[ORM\Index(name: 'idx_es_uf_municipio', columns: ['uf', 'municipio'])]
#[ORM\Index(name: 'idx_es_status', columns: ['status'])]
#[ORM\Index(name: 'idx_es_reservado_para', columns: ['reservado_para'])]
#[ORM\Index(name: 'idx_es_uf_ativo_status_reservado_em', columns: ['uf', 'ativo', 'status', 'reservado_em'])]
class EstabelecimentoSaude
{
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

    /** Código CNES (chave de identidade, 7 dígitos). */
    #[ORM\Column(length: 10)]
    private string $cnes = '';

    #[ORM\Column(name: 'nome_fantasia', length: 255, nullable: true)]
    private ?string $nomeFantasia = null;

    /** true quando o STATUS DO ESTABELECIMENTO no CNES é ATIVO. */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $ativo = true;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $uf = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $municipio = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $logradouro = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $telefone = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 8, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 8, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(name: 'link_waze', type: 'string', length: 1000, nullable: true)]
    private ?string $linkWaze = null;

    #[ORM\Column(name: 'row_hash', length: 64)]
    private string $rowHash = '';

    #[ORM\Column(name: 'imported_at')]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(name: 'updated_at', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(name: 'reservado_para', length: 100, nullable: true)]
    private ?string $reservadoPara = null;

    #[ORM\Column(name: 'reservado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reservadoEm = null;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCnes(): string { return $this->cnes; }
    public function setCnes(string $v): self { $this->cnes = $v; return $this; }

    public function getNomeFantasia(): ?string { return $this->nomeFantasia; }
    public function setNomeFantasia(?string $v): self { $this->nomeFantasia = $v; return $this; }

    public function isAtivo(): bool { return $this->ativo; }
    public function setAtivo(bool $v): self { $this->ativo = $v; return $this; }

    public function getUf(): ?string { return $this->uf; }
    public function setUf(?string $v): self { $this->uf = $v; return $this; }

    public function getMunicipio(): ?string { return $this->municipio; }
    public function setMunicipio(?string $v): self { $this->municipio = $v; return $this; }

    public function getLogradouro(): ?string { return $this->logradouro; }
    public function setLogradouro(?string $v): self { $this->logradouro = $v; return $this; }

    public function getTelefone(): ?string { return $this->telefone; }
    public function setTelefone(?string $v): self { $this->telefone = $v; return $this; }

    public function getLatitude(): ?string { return $this->latitude; }
    public function setLatitude(?string $v): self { $this->latitude = $v; return $this; }

    public function getLongitude(): ?string { return $this->longitude; }
    public function setLongitude(?string $v): self { $this->longitude = $v; return $this; }

    public function getLinkWaze(): ?string { return $this->linkWaze; }
    public function setLinkWaze(?string $v): self { $this->linkWaze = $v; return $this; }

    public function getRowHash(): string { return $this->rowHash; }
    public function setRowHash(string $v): self { $this->rowHash = $v; return $this; }

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
    public function setReservadoEm(?\DateTimeImmutable $v): self { $this->reservadoEm = $v; return $this; }
}