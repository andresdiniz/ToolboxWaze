<?php

namespace App\Repository;

use App\Entity\Solicitacao;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SolicitacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Solicitacao::class);
    }

    public function findPendentesDoResponsavel(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.responsaveis', 'r')
            ->where('r = :user')
            ->andWhere('s.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', Solicitacao::STATUS_PENDENTE)
            ->orderBy('s.criadaEm', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countPendentesDoResponsavel(User $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.responsaveis', 'r')
            ->where('r = :user')
            ->andWhere('s.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', Solicitacao::STATUS_PENDENTE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findAllPendentes(): array
    {
        return $this->findBy(['status' => Solicitacao::STATUS_PENDENTE], ['criadaEm' => 'DESC']);
    }

    public function findByTipoAndStatus(string $tipo, string $status = Solicitacao::STATUS_PENDENTE): array
    {
        return $this->findBy(['tipo' => $tipo, 'status' => $status], ['criadaEm' => 'DESC']);
    }

    /** Histórico do solicitante pelo e-mail informado no formulário */
    public function findByEmail(string $email): array
    {
        return $this->createQueryBuilder('s')
            ->where('LOWER(s.solicitanteEmail) = LOWER(:email)')
            ->setParameter('email', $email)
            ->orderBy('s.criadaEm', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Listagem para gestão admin com filtros opcionais */
    public function findParaGestao(?string $status = null, ?string $tipo = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.criadaEm', 'DESC');

        if ($status) {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }
        if ($tipo) {
            $qb->andWhere('s.tipo = :tipo')->setParameter('tipo', $tipo);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status, COUNT(s.id) as total')
            ->groupBy('s.status')
            ->getQuery()
            ->getResult();

        $map = ['pendente' => 0, 'resolvida' => 0, 'cancelada' => 0];
        foreach ($rows as $r) {
            $map[$r['status']] = (int) $r['total'];
        }
        return $map;
    }
}
