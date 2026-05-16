<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RadarHistoricoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registro histórico de verificação de um radar.
 */
#[ORM\Entity(repositoryClass: RadarHistoricoRepository::class)]
#[ORM\Table(name: 'radar_historico')]
#[ORM\Index(name: 'idx_hist_radar',       columns: ['radar_medidor_id'])]
#[ORM\Index(name: 'idx_hist_certificado', columns: ['numero_certificado'])]
#[ORM\Index(name: 'idx_hist_ano',         columns: ['ano'])]
class RadarHistorico
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RadarMedidor::class)]
    #[ORM\JoinColumn(name: 'radar_medidor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private RadarMedidor $radarMedidor;

    #[ORM\Column(name: 'numero_certificado', type: 'string', length: 50, nullable: true)]
    private ?string $numeroCertificado = null;

    #[ORM\Column(name: 'numero_ensaio', type: 'string', length: 20, nullable: true)]
    private ?string $numeroEnsaio = null;

    #[ORM\Column(type: 'string', length: 4, nullable: true)]
    private ?string $ano = null;

    #[ORM\Column(name: 'data_laudo', type: 'string', length: 20, nullable: true)]
    private ?string $dataLaudo = null;

    #[ORM\Column(name: 'data_validade', type: 'string', length: 20, nullable: true)]
    private ?string $dataValidade = null;

    #[ORM\Column(name: 'tipo_servico', type: 'string', length: 50, nullable: true)]
    private ?string $tipoServico = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $resultado = null;

    // Getters
    public function getId(): ?int                   { return $this->id; }
    public function getRadarMedidor(): RadarMedidor { return $this->radarMedidor; }
    public function getNumeroCertificado(): ?string { return $this->numeroCertificado; }
    public function getNumeroEnsaio(): ?string      { return $this->numeroEnsaio; }
    public function getAno(): ?string               { return $this->ano; }
    public function getDataLaudo(): ?string         { return $this->dataLaudo; }
    public function getDataValidade(): ?string      { return $this->dataValidade; }
    public function getTipoServico(): ?string       { return $this->tipoServico; }
    public function getResultado(): ?string         { return $this->resultado; }

    // Setters
    public function setRadarMedidor(RadarMedidor $v): static    { $this->radarMedidor = $v; return $this; }
    public function setNumeroCertificado(?string $v): static    { $this->numeroCertificado = $v; return $this; }
    public function setNumeroEnsaio(?string $v): static         { $this->numeroEnsaio = $v; return $this; }
    public function setAno(?string $v): static                  { $this->ano = $v; return $this; }
    public function setDataLaudo(?string $v): static            { $this->dataLaudo = $v; return $this; }
    public function setDataValidade(?string $v): static         { $this->dataValidade = $v; return $this; }
    public function setTipoServico(?string $v): static          { $this->tipoServico = $v; return $this; }
    public function setResultado(?string $v): static            { $this->resultado = $v; return $this; }
}
