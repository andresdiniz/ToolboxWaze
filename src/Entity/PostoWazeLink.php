<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PostoWazeLinkRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Link do Editor de Mapas do Waze associado a um FuelResellerRaw (posto).
 *
 * Espelha RadarWazeLink, porém referencia fuel_reseller_raw.
 * Histórico completo de alterações fica em PostoWazeLinkLog (OneToMany).
 *
 * NOTA: postos usam o parâmetro venues= (não permanentHazards= dos radares).
 */
#[ORM\Entity(repositoryClass: PostoWazeLinkRepository::class)]
#[ORM\Table(name: 'posto_waze_link')]
#[ORM\UniqueConstraint(name: 'uq_waze_link_posto', columns: ['posto_id'])]
class PostoWazeLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    /** Posto ao qual este link pertence (1 posto → 1 link ativo) */
    #[ORM\ManyToOne(targetEntity: FuelResellerRaw::class)]
    #[ORM\JoinColumn(name: 'posto_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private FuelResellerRaw $posto;

    /**
     * URL completa do Editor de Mapas do Waze.
     * Deve conter o parâmetro venues=<número>.
     */
    #[ORM\Column(name: 'waze_link', type: 'string', length: 1000)]
    #[Assert\NotBlank(message: 'O link do Waze é obrigatório.')]
    #[Assert\Url(message: 'Informe uma URL válida.')]
    #[Assert\Regex(
        pattern: '/[?&]venues=\d+/',
        message: 'O link deve conter o parâmetro venues com valor numérico (ex: &venues=207160888).'
    )]
    private string $wazeLink;

    /** ID numérico extraído de venues= — indexado para buscas */
    #[ORM\Column(name: 'venue_id', type: 'integer', options: ['unsigned' => true])]
    private int $venueId;

    /** Usuário que inseriu o link */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'inserted_by', referencedColumnName: 'id', nullable: false)]
    private User $insertedBy;

    #[ORM\Column(name: 'inserted_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $insertedAt;

    /** Último usuário a alterar o link (null se nunca alterado) */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by', referencedColumnName: 'id', nullable: true)]
    private ?User $updatedBy = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** Observação livre sobre o link (opcional) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observacao = null;

    /**
     * Histórico completo de alterações deste link.
     *
     * @var Collection<int, PostoWazeLinkLog>
     */
    #[ORM\OneToMany(
        targetEntity: PostoWazeLinkLog::class,
        mappedBy: 'postoWazeLink',
        cascade: ['persist'],
        orphanRemoval: true,
        fetch: 'EXTRA_LAZY',
    )]
    #[ORM\OrderBy(['changedAt' => 'DESC'])]
    private Collection $logs;

    public function __construct()
    {
        $this->logs = new ArrayCollection();
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getId(): ?int                              { return $this->id; }
    public function getPosto(): FuelResellerRaw                 { return $this->posto; }
    public function getWazeLink(): string                       { return $this->wazeLink; }
    public function getVenueId(): int                           { return $this->venueId; }
    public function getInsertedBy(): User                       { return $this->insertedBy; }
    public function getInsertedAt(): \DateTimeImmutable          { return $this->insertedAt; }
    public function getUpdatedBy(): ?User                       { return $this->updatedBy; }
    public function getUpdatedAt(): ?\DateTimeImmutable          { return $this->updatedAt; }
    public function getObservacao(): ?string                    { return $this->observacao; }

    /** @return Collection<int, PostoWazeLinkLog> */
    public function getLogs(): Collection                       { return $this->logs; }

    // -------------------------------------------------------------------------
    // Setters
    // -------------------------------------------------------------------------

    public function setPosto(FuelResellerRaw $v): static        { $this->posto = $v; return $this; }
    public function setInsertedBy(User $v): static              { $this->insertedBy = $v; return $this; }
    public function setInsertedAt(\DateTimeImmutable $v): static { $this->insertedAt = $v; return $this; }
    public function setUpdatedBy(?User $v): static              { $this->updatedBy = $v; return $this; }
    public function setUpdatedAt(?\DateTimeImmutable $v): static { $this->updatedAt = $v; return $this; }
    public function setObservacao(?string $v): static           { $this->observacao = $v; return $this; }

    /**
     * Valida e seta o link, extraindo venueId automaticamente.
     *
     * @throws \InvalidArgumentException se o link não contiver venues=.
     */
    public function setWazeLink(string $url): static
    {
        $venueId = self::extractVenueId($url);

        if ($venueId === null) {
            throw new \InvalidArgumentException(
                'O link do Waze deve conter o parâmetro venues com valor numérico (ex: &venues=207160888).'
            );
        }

        $this->wazeLink = $url;
        $this->venueId  = $venueId;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Helper estático
    // -------------------------------------------------------------------------

    public static function extractVenueId(string $url): ?int
    {
        if (preg_match('/[?&]venues=(\d+)/', $url, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
