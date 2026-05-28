<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EscolaInepComentarioRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Comentário de editor associado a uma EscolaInep.
 * Modelo análogo ao SolicitacaoComentario.
 */
#[ORM\Entity(repositoryClass: EscolaInepComentarioRepository::class)]
#[ORM\Table(name: 'escola_inep_comentario')]
#[ORM\Index(name: 'idx_eic_escola', columns: ['escola_id'])]
class EscolaInepComentario
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EscolaInep::class, inversedBy: 'comentarios')]
    #[ORM\JoinColumn(name: 'escola_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private EscolaInep $escola;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'autor_id', referencedColumnName: 'id', nullable: false)]
    private User $autor;

    #[ORM\Column(type: 'text')]
    private string $texto = '';

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int                         { return $this->id; }
    public function getEscola(): EscolaInep               { return $this->escola; }
    public function getAutor(): User                      { return $this->autor; }
    public function getTexto(): string                    { return $this->texto; }
    public function getCriadoEm(): \DateTimeImmutable      { return $this->criadoEm; }

    public function setEscola(EscolaInep $v): self { $this->escola = $v; return $this; }
    public function setAutor(User $v): self        { $this->autor  = $v; return $this; }
    public function setTexto(string $v): self      { $this->texto  = trim($v); return $this; }
}
