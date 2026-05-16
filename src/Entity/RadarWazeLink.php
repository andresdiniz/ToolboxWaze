<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RadarWazeLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Link do Editor de Mapas do Waze associado a um RadarMedidor.
 *
 * Regra de negócio obrigatória:
 *   O link deve ser uma URL do editor Waze contendo o parâmetro
 *   «permanentHazards» com valor numérico.
 *   Ex.: https://waze.com/pt-BR/editor?env=row&lat=-20.59714&lon=-43.79811&zoomLevel=16&permanentHazards=272464
 *
 * Histórico de alterações fica em RadarWazeLinkLog.
 */
#[ORM\Entity(repositoryClass: RadarWazeLinkRepository::class)]
#[ORM\Table(name: 'radar_waze_link')]
#[ORM\UniqueConstraint(name: 'uq_waze_link_radar', columns: ['radar_medidor_id'])]
class RadarWazeLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    /** Radar ao qual este link pertence (1 radar → 1 link ativo) */
    #[ORM\ManyToOne(targetEntity: RadarMedidor::class)]
    #[ORM\JoinColumn(name: 'radar_medidor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private RadarMedidor $radarMedidor;

    /**
     * URL completa do Editor de Mapas do Waze.
     * Deve conter o parâmetro permanentHazards=<número>.
     */
    #[ORM\Column(name: 'waze_link', type: 'string', length: 1000)]
    #[Assert\NotBlank(message: 'O link do Waze é obrigatório.')]
    #[Assert\Url(message: 'Informe uma URL válida.')]
    #[Assert\Regex(
        pattern: '/[?&]permanentHazards=\d+/',
        message: 'O link deve conter o parâmetro permanentHazards com valor numérico (ex: &permanentHazards=272464).'
    )]
    private string $wazeLink;

    /** ID numérico extraído de permanentHazards — indexado para buscas */
    #[ORM\Column(name: 'permanent_hazard_id', type: 'integer', options: ['unsigned' => true])]
    private int $permanentHazardId;

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

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getId(): ?int                              { return $this->id; }
    public function getRadarMedidor(): RadarMedidor             { return $this->radarMedidor; }
    public function getWazeLink(): string                       { return $this->wazeLink; }
    public function getPermanentHazardId(): int                 { return $this->permanentHazardId; }
    public function getInsertedBy(): User                       { return $this->insertedBy; }
    public function getInsertedAt(): \DateTimeImmutable          { return $this->insertedAt; }
    public function getUpdatedBy(): ?User                       { return $this->updatedBy; }
    public function getUpdatedAt(): ?\DateTimeImmutable          { return $this->updatedAt; }
    public function getObservacao(): ?string                    { return $this->observacao; }

    // -------------------------------------------------------------------------
    // Setters
    // -------------------------------------------------------------------------

    public function setRadarMedidor(RadarMedidor $v): static    { $this->radarMedidor = $v; return $this; }
    public function setInsertedBy(User $v): static              { $this->insertedBy = $v; return $this; }
    public function setInsertedAt(\DateTimeImmutable $v): static { $this->insertedAt = $v; return $this; }
    public function setUpdatedBy(?User $v): static              { $this->updatedBy = $v; return $this; }
    public function setUpdatedAt(?\DateTimeImmutable $v): static { $this->updatedAt = $v; return $this; }
    public function setObservacao(?string $v): static           { $this->observacao = $v; return $this; }

    /**
     * Valida e seta o link, extraindo permanentHazardId automaticamente.
     *
     * @throws \InvalidArgumentException se o link não contiver permanentHazards.
     */
    public function setWazeLink(string $url): static
    {
        $hazardId = self::extractPermanentHazardId($url);

        if ($hazardId === null) {
            throw new \InvalidArgumentException(
                'O link do Waze deve conter o parâmetro permanentHazards com valor numérico.'
            );
        }

        $this->wazeLink          = $url;
        $this->permanentHazardId = $hazardId;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Helper estático
    // -------------------------------------------------------------------------

    public static function extractPermanentHazardId(string $url): ?int
    {
        if (preg_match('/[?&]permanentHazards=(\d+)/', $url, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
