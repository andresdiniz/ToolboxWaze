<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Histórico imutável de mesclagens de radares.
 * Cada linha representa um radar "absorvido" (B) que foi fundido num radar sobrevivente (A).
 */
#[ORM\Entity]
#[ORM\Table(name: 'radar_merge_log')]
class RadarMergeLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    /** ID do radar que PERMANECEU (sobrevivente) */
    #[ORM\Column(name: 'survivor_id', type: 'bigint', options: ['unsigned' => true])]
    private int $survivorId;

    /** ID do radar que foi ABSORVIDO (agora marcado merged_into_id) */
    #[ORM\Column(name: 'absorbed_id', type: 'bigint', options: ['unsigned' => true])]
    private int $absorbedId;

    /** Snapshot JSON do radar absorvido antes da mesclagem */
    #[ORM\Column(name: 'absorbed_snapshot', type: 'json', nullable: true)]
    private ?array $absorbedSnapshot = null;

    /** Campos que foram substituídos no sobrevivente (campo => valor_antigo) */
    #[ORM\Column(name: 'fields_overwritten', type: 'json', nullable: true)]
    private ?array $fieldsOverwritten = null;

    /** E-mail do usuário que executou a mesclagem */
    #[ORM\Column(name: 'merged_by', type: 'string', length: 150)]
    private string $mergedBy;

    #[ORM\Column(name: 'merged_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $mergedAt;

    public function __construct(
        int $survivorId,
        int $absorbedId,
        string $mergedBy,
        ?array $absorbedSnapshot = null,
        ?array $fieldsOverwritten = null,
    ) {
        $this->survivorId        = $survivorId;
        $this->absorbedId        = $absorbedId;
        $this->mergedBy          = $mergedBy;
        $this->absorbedSnapshot  = $absorbedSnapshot;
        $this->fieldsOverwritten = $fieldsOverwritten;
        $this->mergedAt          = new \DateTimeImmutable();
    }

    public function getId(): ?int                         { return $this->id; }
    public function getSurvivorId(): int                  { return $this->survivorId; }
    public function getAbsorbedId(): int                  { return $this->absorbedId; }
    public function getAbsorbedSnapshot(): ?array         { return $this->absorbedSnapshot; }
    public function getFieldsOverwritten(): ?array        { return $this->fieldsOverwritten; }
    public function getMergedBy(): string                 { return $this->mergedBy; }
    public function getMergedAt(): \DateTimeImmutable     { return $this->mergedAt; }
}
