<?php

namespace App\Entity;

use App\Repository\FormBuilderRespostaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FormBuilderRespostaRepository::class)]
#[ORM\Table(name: 'form_builder_resposta')]
class FormBuilderResposta
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'respostas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FormBuilder $formulario = null;

    /** UUID gerado no momento do envio para agrupar campos da mesma submissão */
    #[ORM\Column(length: 36)]
    private string $submissaoUuid = '';

    /** Dados completos da resposta: [ 'chave' => valor, ... ] */
    #[ORM\Column(type: 'json')]
    private array $dados = [];

    #[ORM\Column]
    private \DateTimeImmutable $criadoEm;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $usuario = null;

    /** IP do visitante (anonimizado nos últimos octets se necessário) */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    /** Status: pendente | aprovado | rejeitado | arquivado */
    #[ORM\Column(length: 20)]
    private string $status = 'pendente';

    /** Notas internas do admin */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notaAdmin = null;

    public function __construct()
    {
        $this->criadoEm     = new \DateTimeImmutable();
        $this->submissaoUuid = \sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            \mt_rand(0, 0xffff), \mt_rand(0, 0xffff),
            \mt_rand(0, 0xffff),
            \mt_rand(0, 0x0fff) | 0x4000,
            \mt_rand(0, 0x3fff) | 0x8000,
            \mt_rand(0, 0xffff), \mt_rand(0, 0xffff), \mt_rand(0, 0xffff)
        );
    }

    public function getId(): ?int { return $this->id; }

    public function getFormulario(): ?FormBuilder { return $this->formulario; }
    public function setFormulario(?FormBuilder $formulario): static { $this->formulario = $formulario; return $this; }

    public function getSubmissaoUuid(): string { return $this->submissaoUuid; }

    public function getDados(): array { return $this->dados; }
    public function setDados(array $dados): static { $this->dados = $dados; return $this; }

    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }

    public function getUsuario(): ?User { return $this->usuario; }
    public function setUsuario(?User $usuario): static { $this->usuario = $usuario; return $this; }

    public function getIp(): ?string { return $this->ip; }
    public function setIp(?string $ip): static { $this->ip = $ip; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getNotaAdmin(): ?string { return $this->notaAdmin; }
    public function setNotaAdmin(?string $notaAdmin): static { $this->notaAdmin = $notaAdmin; return $this; }
}
