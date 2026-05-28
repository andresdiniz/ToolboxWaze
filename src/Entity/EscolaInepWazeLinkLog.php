<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EscolaInepWazeLinkLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Log de alterações do link Waze de uma EscolaInep.
 * Análogo ao PostoWazeLinkLog.
 */
#[ORM\Entity(repositoryClass: EscolaInepWazeLinkLogRepository::class)]
#[ORM\Table(name: 'escola_inep_waze_link_log')]
#[ORM\Index(name: 'idx_eiwll_escola', columns: ['escola_id'])]
class EscolaInepWazeLinkLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EscolaInep::class)]
    #[ORM\JoinColumn(name: 'escola_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private EscolaInep $escola;

    /** Tipo de campo alterado: link_waze | link_area_escolar */
    #[ORM\Column(name: 'campo', length: 30)]
    private string $campo;

    #[ORM\Column(name: 'valor_anterior', type: 'string', length: 1000, nullable: true)]
    private ?string $valorAnterior = null;

    #[ORM\Column(name: 'valor_novo', type: 'string', length: 1000, nullable: true)]
    private ?string $valorNovo = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por', referencedColumnName: 'id', nullable: false)]
    private User $alteradoPor;

    #[ORM\Column(name: 'alterado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observacao = null;

    public function __construct()
    {
        $this->alteradoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int                        { return $this->id; }
    public function getEscola(): EscolaInep              { return $this->escola; }
    public function getCampo(): string                   { return $this->campo; }
    public function getValorAnterior(): ?string           { return $this->valorAnterior; }
    public function getValorNovo(): ?string               { return $this->valorNovo; }
    public function getAlteradoPor(): User               { return $this->alteradoPor; }
    public function getAlteradoEm(): \DateTimeImmutable   { return $this->alteradoEm; }
    public function getObservacao(): ?string              { return $this->observacao; }

    public function setEscola(EscolaInep $v): self       { $this->escola        = $v; return $this; }
    public function setCampo(string $v): self            { $this->campo         = $v; return $this; }
    public function setValorAnterior(?string $v): self   { $this->valorAnterior = $v; return $this; }
    public function setValorNovo(?string $v): self       { $this->valorNovo     = $v; return $this; }
    public function setAlteradoPor(User $v): self        { $this->alteradoPor   = $v; return $this; }
    public function setObservacao(?string $v): self      { $this->observacao    = $v; return $this; }
}
