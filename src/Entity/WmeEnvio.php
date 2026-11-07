<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'wme_envio')]
class WmeEnvio
{
    #[ORM\Id]
    #[ORM\Column(length: 80)]
    private string $id;
    #[ORM\Column(name: 'user_id')]
    private int $userId;
    #[ORM\Column(length: 64)]
    private string $hash;
    #[ORM\Column(length: 20)]
    private string $estado;
    #[ORM\Column(name: 'solicitacao_id', nullable: true)]
    private ?int $solicitacaoId = null;
    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;
}
