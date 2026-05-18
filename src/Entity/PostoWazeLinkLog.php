<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PostoWazeLinkLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Histórico de alterações de um PostoWazeLink.
 *
 * Cada vez que o link (ou a observação) é alterado, o valor anterior
 * é gravado aqui junto com quem alterou, quando e qual campo mudou.
 * A relação inversa (OneToMany) está em PostoWazeLink::$logs.
 */
#[ORM\Entity(repositoryClass: PostoWazeLinkLogRepository::class)]
#[ORM\Table(name: 'posto_waze_link_log')]
#[ORM\Index(name: 'idx_posto_waze_log_link', columns: ['posto_waze_link_id'])]
class PostoWazeLinkLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    /**
     * Link ao qual este registro pertence.
     * mappedBy espelha PostoWazeLink::$logs.
     */
    #[ORM\ManyToOne(targetEntity: PostoWazeLink::class, inversedBy: 'logs')]
    #[ORM\JoinColumn(name: 'posto_waze_link_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PostoWazeLink $postoWazeLink;

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

    /** Observação opcional do operador ao realizar a alteração */
    #[ORM\Column(name: 'observacao', type: 'text', nullable: true)]
    private ?string $observacao = null;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public static function create(
        PostoWazeLink $link,
        User $changedBy,
        string $campoAlterado,
        ?string $valorAnterior,
        ?string $valorNovo,
        ?string $observacao = null,
    ): self {
        $log = new self();
        $log->postoWazeLink  = $link;
        $log->changedBy      = $changedBy;
        $log->changedAt      = new \DateTimeImmutable();
        $log->campoAlterado  = $campoAlterado;
        $log->valorAnterior  = $valorAnterior;
        $log->valorNovo      = $valorNovo;
        $log->observacao     = $observacao;

        return $log;
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getId(): ?int                              { return $this->id; }
    public function getPostoWazeLink(): PostoWazeLink           { return $this->postoWazeLink; }
    public function getChangedBy(): User                        { return $this->changedBy; }
    public function getChangedAt(): \DateTimeImmutable           { return $this->changedAt; }
    public function getCampoAlterado(): string                  { return $this->campoAlterado; }
    public function getValorAnterior(): ?string                 { return $this->valorAnterior; }
    public function getValorNovo(): ?string                     { return $this->valorNovo; }
    public function getObservacao(): ?string                    { return $this->observacao; }
}
