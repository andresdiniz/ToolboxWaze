<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SolicitacaoTipoResponsavel;
use App\Entity\TipoSolicitacaoConfig;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SolicitacaoTipoResponsavel>
 */
class SolicitacaoTipoResponsavelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SolicitacaoTipoResponsavel::class);
    }

    /**
     * Verifica se o usuário é responsável pelo tipo de solicitação informado.
     *
     * @param TipoSolicitacaoConfig|string|int|null $tipo Entidade, slug ou id
     */
    public function isResponsavel(User $user, mixed $tipo): bool
    {
        if ($tipo === null) {
            return false;
        }

        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.usuario = :user')
            ->setParameter('user', $user);

        if ($tipo instanceof TipoSolicitacaoConfig) {
            $qb->andWhere('r.tipo = :tipo')->setParameter('tipo', $tipo);
        } else {
            // aceita id numérico
            $qb->join('r.tipo', 't')
               ->andWhere('t.id = :tipo')
               ->setParameter('tipo', (int) $tipo);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Retorna todos os responsáveis de um tipo, hidratados como User.
     *
     * @return User[]
     */
    public function findUsersByTipo(TipoSolicitacaoConfig $tipo): array
    {
        return array_map(
            fn (SolicitacaoTipoResponsavel $r) => $r->getUsuario(),
            $this->findBy(['tipo' => $tipo])
        );
    }
}
