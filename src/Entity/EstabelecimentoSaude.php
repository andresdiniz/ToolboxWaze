<?php

declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'estabelecimento_saude')]
class EstabelecimentoSaude
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 7, unique: true)]
    private string $cnes;

    #[ORM\Column(type: 'json')]
    private array $dadosOriginais = [];

    #[ORM\Column(type: 'json')]
    private array $correcoes = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $importadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $editadoEm = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $editadoPor = null;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $versao = 1;

    public function getId(): ?int { return $this->id; }
    public function getCnes(): string { return $this->cnes; }
    public function getDadosOriginais(): array { return $this->dadosOriginais; }
    public function getCorrecoes(): array { return $this->correcoes; }
    public function getDados(): array { return array_replace($this->dadosOriginais, $this->correcoes); }
    public function getImportadoEm(): \DateTimeImmutable { return $this->importadoEm; }
    public function getEditadoEm(): ?\DateTimeImmutable { return $this->editadoEm; }
    public function getEditadoPor(): ?int { return $this->editadoPor; }
    public function getVersao(): int { return $this->versao; }
    public function getUf(): string { return (string) ($this->dadosOriginais['UF'] ?? ''); }
    public function getNome(): string { return (string) ($this->getDados()['NOME FANTASIA'] ?? $this->cnes); }

    public function aplicarCorrecoes(array $valores, int $editor): void
    {
        $correcoes = [];
        foreach ($valores as $campo => $valor) {
            if (in_array($campo, ['CNES', 'UF', 'ANO', 'COMPETÊNCIA'], true)) continue;
            if (array_key_exists($campo, $this->dadosOriginais)
                && $valor !== $this->dadosOriginais[$campo]) {
                $correcoes[$campo] = $valor;
            }
        }
        $this->correcoes = $correcoes;
        $this->editadoEm = new \DateTimeImmutable();
        $this->editadoPor = $editor;
    }
}
