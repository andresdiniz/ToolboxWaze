<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RadarFaixaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Faixa de um radar medidor.
 * Um radar pode ter várias faixas (ex: faixa 1 e faixa 2).
 */
#[ORM\Entity(repositoryClass: RadarFaixaRepository::class)]
#[ORM\Table(name: 'radar_faixa')]
#[ORM\Index(name: 'idx_faixa_radar',         columns: ['radar_medidor_id'])]
#[ORM\Index(name: 'idx_faixa_numero_inmetro', columns: ['numero_inmetro'])]
#[ORM\Index(name: 'idx_faixa_numero_serie',   columns: ['numero_serie'])]
class RadarFaixa
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RadarMedidor::class)]
    #[ORM\JoinColumn(name: 'radar_medidor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private RadarMedidor $radarMedidor;

    #[ORM\Column(name: 'numero_faixa', type: 'string', length: 10, nullable: true)]
    private ?string $numeroFaixa = null;

    #[ORM\Column(name: 'numero_inmetro', type: 'string', length: 50, nullable: true)]
    private ?string $numeroInmetro = null;

    #[ORM\Column(name: 'numero_serie', type: 'string', length: 100, nullable: true)]
    private ?string $numeroSerie = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $sentido = null;

    #[ORM\Column(name: 'velocidade_nominal', type: 'string', length: 20, nullable: true)]
    private ?string $velocidadeNominal = null;

    // Getters
    public function getId(): ?int                   { return $this->id; }
    public function getRadarMedidor(): RadarMedidor { return $this->radarMedidor; }
    public function getNumeroFaixa(): ?string       { return $this->numeroFaixa; }
    public function getNumeroInmetro(): ?string     { return $this->numeroInmetro; }
    public function getNumeroSerie(): ?string       { return $this->numeroSerie; }
    public function getSentido(): ?string           { return $this->sentido; }
    public function getVelocidadeNominal(): ?string { return $this->velocidadeNominal; }

    // Setters
    public function setRadarMedidor(RadarMedidor $v): static      { $this->radarMedidor = $v; return $this; }
    public function setNumeroFaixa(?string $v): static            { $this->numeroFaixa = $v; return $this; }
    public function setNumeroInmetro(?string $v): static          { $this->numeroInmetro = $v; return $this; }
    public function setNumeroSerie(?string $v): static            { $this->numeroSerie = $v; return $this; }
    public function setSentido(?string $v): static                { $this->sentido = $v; return $this; }
    public function setVelocidadeNominal(?string $v): static      { $this->velocidadeNominal = $v; return $this; }
}
