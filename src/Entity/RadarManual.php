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
 *
 * Chaves de merge (ordem de prioridade):
 *   1. numero_serie  — identificador mais confiável; único por faixa na fonte RBMLQ
 *   2. identity_hash — SHA-256(UF|LOCAL_VERIFICACAO|TIPO_MEDIDOR); fallback quando
 *                      o número de série não for informado ou ainda não estiver na fonte
 */
#[ORM\Entity(repositoryClass: RadarManualRepository::class)]
#[ORM\Table(name: 'radar_manual')]
#[ORM\Index(columns: ['identity_hash'], name: 'idx_radar_manual_identity')]
#[ORM\Index(columns: ['status'],        name: 'idx_radar_manual_status')]
#[ORM\Index(columns: ['numero_serie'],  name: 'idx_radar_manual_serie')]
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

    /** Marca do equipamento (ex: PARDINI, CINEMOMETER) */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $marca = null;

    /**
     * Número de série do equipamento.
     * É a chave de merge mais confiável: único por RadarFaixa na fonte RBMLQ.
     * Quando preenchido, o sistema primeiro tenta localizar o radar_medidor
     * via JOIN em radar_faixa.numero_serie antes de usar o identity_hash.
     */
    #[ORM\Column(name: 'numero_serie', length: 100, nullable: true)]
    private ?string $numeroSerie = null;

    /**
     * Fonte da informação usada para cadastrar este radar.
     * Pode ser uma URL (Diretran, prefeitura, notícia, Waze) ou texto livre.
     * Exibida na interface e gravada no log de merge para rastreabilidade.
     */
    #[ORM\Column(name: 'fonte', type: 'string', length: 1000, nullable: true)]
    private ?string $fonte = null;

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
     * SHA-256( UF | LOCAL_VERIFICACAO | TIPO_MEDIDOR [¦ NUMERO_SERIE] )
     * Se numero_serie estiver preenchido, ele entra no hash para reduzir
     * colisões entre radares do mesmo local com equipamentos diferentes.
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

    /**
     * FK fixada com o nome real do índice no banco (fk_rm_criado_por),
     * evitando que o migrations:diff gere renomeações/drops destrutivos.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'criado_por_id', referencedColumnName: 'id', nullable: true)]
    private ?User $criadoPor = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    // ── Getters / Setters ───────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getSiglaUf(): string { return $this->siglaUf; }
    public function setSiglaUf(string $v): static { $this->siglaUf = strtoupper($v); return $this; }

    public function getMunicipio(): string { return $this->municipio; }
    public function setMunicipio(string $v): static { $this->municipio = $v; return $this; }

    public function getLocalVerificacao(): string { return $this->localVerificacao; }
    public function setLocalVerificacao(string $v): static { $this->localVerificacao = $v; return $this; }

    public function getTipoMedidor(): ?string { return $this->tipoMedidor; }
    public function setTipoMedidor(?string $v): static { $this->tipoMedidor = $v; return $this; }

    public function getMarca(): ?string { return $this->marca; }
    public function setMarca(?string $v): static { $this->marca = $v ?: null; return $this; }

    public function getNumeroSerie(): ?string { return $this->numeroSerie; }
    public function setNumeroSerie(?string $v): static { $this->numeroSerie = $v ? trim($v) : null; return $this; }

    public function getFonte(): ?string { return $this->fonte; }
    public function setFonte(?string $v): static { $this->fonte = $v ?: null; return $this; }

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

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Recalcula e grava o identity_hash a partir dos campos atuais.
     * Chamar sempre antes de persistir.
     *
     * Hash = SHA-256( UF | LOCAL_VERIFICACAO | TIPO_MEDIDOR [| NUMERO_SERIE] )
     * O número de série só entra no hash quando fornecido, para que registros
     * antigos sem série ainda batam corretamente com a fonte.
     */
    public function recalcIdentityHash(): void
    {
        $parts = [
            strtoupper($this->siglaUf),
            strtoupper(trim($this->localVerificacao)),
            strtoupper(trim((string) $this->tipoMedidor)),
        ];

        if ($this->numeroSerie !== null && $this->numeroSerie !== '') {
            $parts[] = strtoupper(trim($this->numeroSerie));
        }

        $this->identityHash = hash('sha256', implode('|', $parts));
    }

    /**
     * Retorna o nível de confiança do merge para exibir na UI.
     * Alto   = tem número de série (match exato via radar_faixa)
     * Médio  = tem local + tipo (identity_hash)
     * Baixo  = só local (sem tipo informado)
     */
    public function getMergeQuality(): string
    {
        if ($this->numeroSerie !== null && $this->numeroSerie !== '') {
            return 'alto';
        }
        if ($this->tipoMedidor !== null) {
            return 'medio';
        }
        return 'baixo';
    }
}
