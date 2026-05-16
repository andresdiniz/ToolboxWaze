<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RadarWazeLinkLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Histórico de alterações de um RadarWazeLink.
 *
 * Cada vez que o link é alterado, o valor anterior é gravado aqui
 * junto com quem alterou, quando e qual foi a mudança (campo alterado).
 */
#[ORM\Entity(repositoryClass: RadarWazeLinkLogRepository::class)]
#[ORM\Table(name: 'radar_waze_link_log')]
#[ORM\Index(name: 'idx_waze_log_link', columns: ['radar_waze_link_id'])]
class RadarWazeLinkLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RadarWazeLink::class)]
    #[ORM\JoinColumn(name: 'radar_waze_link_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private RadarWazeLink $radarWazeLink;

    /** Usuário que fez a alteração */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'changed_by', referencedColumnName: 'id', nullable: false)]
    private User $changedBy;

    #[ORM\Column(name: 'changed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $changedAt;

    /** Campo que foi alterado (ex: "waze_link", "observacao") */
    #[ORM\Column(name: 'campo_alterado', type: 'string', length: 60)]
    private string $campoAlterado;

    /** Valor anterior (antes da alteração) */
    #[ORM\Column(name: 'valor_anterior', type: 'text', nullable: true)]
    private ?string $valorAnterior = null;

    /** Novo valor (após a alteração) */
    #[ORM\Column(name: 'valor_novo', type: 'text', nullable: true)]
    private ?string $valorNovo = null;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public static function create(
        RadarWazeLink $link,
        User $changedBy,
        string $campoAlterado,
        ?string $valorAnterior,
        ?string $valorNovo,
    ): self {
        $log = new self();
        $log->radarWazeLink  = $link;
        $log->changedBy      = $changedBy;
        $log->changedAt      = new \DateTimeImmutable();
        $log->campoAlterado  = $campoAlterado;
        $log->valorAnterior  = $valorAnterior;
        $log->valorNovo      = $valorNovo;

        return $log;
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getId(): ?int                              { return $this->id; }
    public function getRadarWazeLink(): RadarWazeLink           { return $this->radarWazeLink; }
    public function getChangedBy(): User                        { return $this->changedBy; }
    public function getChangedAt(): \DateTimeImmutable           { return $this->changedAt; }
    public function getCampoAlterado(): string                  { return $this->campoAlterado; }
    public function getValorAnterior(): ?string                 { return $this->valorAnterior; }
    public function getValorNovo(): ?string                     { return $this->valorNovo; }
}
