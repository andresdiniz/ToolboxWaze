<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RadarManualRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Radar inserido manualmente pelo usuário antes de aparecer na fonte oficial.
 *
 * Ciclo de vida:
 *   pendente  → radar existe só aqui, ainda não apareceu no INMETRO/Sheets.
 *   mesclado  → identity_hash bateu com um radar_medidor durante a importação;
 *               os dados oficiais foram copiados para radar_medidor e este
 *               registro é marcado como mesclado (referência mantida para auditoria).
 */
#[ORM\Entity(repositoryClass: RadarManualRepository::class)]
#[ORM\Table(name: 'radar_manual')]
#[ORM\Index(columns: ['identity_hash'], name: 'idx_radar_manual_identity')]
#[ORM\Index(columns: ['status'], name: 'idx_radar_manual_status')]
class RadarManual
{
    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_MESCLADO = 'mesclado';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    /** UF do radar (ex: MG, SP) */
    #[ORM\Column(length: 2)]
    private string $siglaUf = '';

    /** Município */
    #[ORM\Column(length: 255)]
    private string $municipio = '';

    /** Descrição do local de verificação — usado no identity_hash */
    #[ORM\Column(length: 500)]
    private string $localVerificacao = '';

    /** Tipo do medidor (ex: FIXO, PORTÁTIL) — usado no identity_hash */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $tipoMedidor = null;

    /** Velocidade máxima (opcional, informativo) */
    #[ORM\Column(nullable: true)]
    private ?int $velocidade = null;

    /** Sentido da via (opcional) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sentido = null;

    /** Observações livres do usuário */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observacoes = null;

    /**
     * SHA-256( UF | LOCAL_VERIFICACAO | TIPO_MEDIDOR ) — mesmo algoritmo
     * usado pelos handlers de importação para identificar o radar.
     */
    #[ORM\Column(length: 64)]
    private string $identityHash = '';

    /** pendente | mesclado */
    #[ORM\Column(length: 20, options: ['default' => 'pendente'])]
    private string $status = self::STATUS_PENDENTE;

    /** ID do radar_medidor gerado no merge (null enquanto pendente) */
    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $radarMedidorId = null;

    /** Quando o merge ocorreu */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $mescladoEm = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $criadoPor = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    // ── Getters / Setters ─────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getSiglaUf(): string { return $this->siglaUf; }
    public function setSiglaUf(string $v): static { $this->siglaUf = strtoupper($v); return $this; }

    public function getMunicipio(): string { return $this->municipio; }
    public function setMunicipio(string $v): static { $this->municipio = $v; return $this; }

    public function getLocalVerificacao(): string { return $this->localVerificacao; }
    public function setLocalVerificacao(string $v): static { $this->localVerificacao = $v; return $this; }

    public function getTipoMedidor(): ?string { return $this->tipoMedidor; }
    public function setTipoMedidor(?string $v): static { $this->tipoMedidor = $v; return $this; }

    public function getVelocidade(): ?int { return $this->velocidade; }
    public function setVelocidade(?int $v): static { $this->velocidade = $v; return $this; }

    public function getSentido(): ?string { return $this->sentido; }
    public function setSentido(?string $v): static { $this->sentido = $v; return $this; }

    public function getObservacoes(): ?string { return $this->observacoes; }
    public function setObservacoes(?string $v): static { $this->observacoes = $v; return $this; }

    public function getIdentityHash(): string { return $this->identityHash; }
    public function setIdentityHash(string $v): static { $this->identityHash = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function isPendente(): bool { return $this->status === self::STATUS_PENDENTE; }
    public function isMesclado(): bool { return $this->status === self::STATUS_MESCLADO; }

    public function getRadarMedidorId(): ?int { return $this->radarMedidorId; }
    public function setRadarMedidorId(?int $v): static { $this->radarMedidorId = $v; return $this; }

    public function getMescladoEm(): ?\DateTimeImmutable { return $this->mescladoEm; }
    public function setMescladoEm(?\DateTimeImmutable $v): static { $this->mescladoEm = $v; return $this; }

    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }

    public function getCriadoPor(): ?User { return $this->criadoPor; }
    public function setCriadoPor(?User $v): static { $this->criadoPor = $v; return $this; }

    /**
     * Recalcula e grava o identity_hash a partir dos campos atuais.
     * Chamar sempre antes de persistir.
     */
    public function recalcIdentityHash(): void
    {
        $this->identityHash = hash('sha256', implode('|', [
            strtoupper($this->siglaUf),
            strtoupper(trim($this->localVerificacao)),
            strtoupper(trim((string) $this->tipoMedidor)),
        ]));
    }
}
