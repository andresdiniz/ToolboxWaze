<?php

namespace App\Entity;

use App\Repository\NotificacaoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificacaoRepository::class)]
#[ORM\Table(name: 'notificacoes')]
#[ORM\Index(columns: ['usuario_id', 'lida'], name: 'idx_notif_usuario_lida')]
class Notificacao
{
    public const TIPO_NOVA_SOLICITACAO  = 'nova_solicitacao';
    public const TIPO_STATUS_ALTERADO   = 'status_alterado';
    public const TIPO_NOVO_COMENTARIO   = 'novo_comentario';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $usuario;

    #[ORM\ManyToOne(targetEntity: Solicitacao::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Solicitacao $solicitacao = null;

    #[ORM\Column(length: 64)]
    private string $tipo;

    #[ORM\Column(type: 'text')]
    private string $mensagem;

    #[ORM\Column]
    private bool $lida = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    public function __construct()
    {
        $this->criadaEm = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUsuario(): User { return $this->usuario; }
    public function setUsuario(User $u): static { $this->usuario = $u; return $this; }

    public function getSolicitacao(): ?Solicitacao { return $this->solicitacao; }
    public function setSolicitacao(?Solicitacao $s): static { $this->solicitacao = $s; return $this; }

    public function getTipo(): string { return $this->tipo; }
    public function setTipo(string $v): static { $this->tipo = $v; return $this; }

    public function getMensagem(): string { return $this->mensagem; }
    public function setMensagem(string $v): static { $this->mensagem = $v; return $this; }

    public function isLida(): bool { return $this->lida; }
    public function setLida(bool $v): static { $this->lida = $v; return $this; }

    public function getCriadaEm(): \DateTimeImmutable { return $this->criadaEm; }
}
