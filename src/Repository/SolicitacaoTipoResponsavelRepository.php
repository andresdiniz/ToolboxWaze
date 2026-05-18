<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SolicitacaoTipoResponsavel;
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
     * SolicitacaoTipoResponsavel#tipo é uma coluna string simples
     * (ex: 'gerente_estado_pais'), não uma associação ORM.
     * A comparação é feita diretamente sem JOIN.
     *
     * @param string|null $tipo Slug do tipo (ex: 'oops', 'nivel', ...)
     */
    public function isResponsavel(User $user, ?string $tipo): bool
    {
        if ($tipo === null || $tipo === '') {
            return false;
        }

        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.responsaveis', 'u')
            ->where('u = :user')
            ->andWhere('r.tipo = :tipo')
            ->setParameter('user', $user)
            ->setParameter('tipo', $tipo)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * Retorna os Users responsáveis por um tipo.
     *
     * @return User[]
     */
    public function findUsersByTipo(string $tipo): array
    {
        $row = $this->findOneBy(['tipo' => $tipo]);

        return $row ? $row->getResponsaveis()->toArray() : [];
    }
}
