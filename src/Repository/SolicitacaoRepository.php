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

    /**
     * Listagem para gestão com filtros opcionais.
     *
     * @param bool $excluirDowngrade  Oculta solicitações de downgrade (visão admin)
     * @param bool $apenasDowngrade   Mostra apenas downgrade (visão champ)
     */
    public function findParaGestao(
        ?string $status          = null,
        ?string $tipo            = null,
        bool    $excluirDowngrade = false,
        bool    $apenasDowngrade  = false
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.criadaEm', 'DESC');

        if ($status) {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }
        if ($tipo) {
            $qb->andWhere('s.tipo = :tipo')->setParameter('tipo', $tipo);
        }

        // Downgrade é identificado pelo campo JSON dados->tipoNivel = 'downgrade'
        // Usando JSON_EXTRACT (MySQL/MariaDB) ou fallback com LIKE
        if ($excluirDowngrade) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->neq('s.tipo', ':tipoNivel'),
                    $qb->expr()->notLike('s.dados', ':downgradePattern')
                )
            )
            ->setParameter('tipoNivel', Solicitacao::TIPO_NIVEL)
            ->setParameter('downgradePattern', '%"tipoNivel":"downgrade"%');
        }

        if ($apenasDowngrade) {
            $qb->andWhere('s.tipo = :tipoNivel')
               ->andWhere($qb->expr()->like('s.dados', ':downgradePattern'))
               ->setParameter('tipoNivel', Solicitacao::TIPO_NIVEL)
               ->setParameter('downgradePattern', '%"tipoNivel":"downgrade"%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param bool $excluirDowngrade  Exclui contagem de downgrade (visão admin)
     * @param bool $apenasDowngrade   Conta apenas downgrade (visão champ)
     */
    public function countByStatus(
        ?string $tipo            = null,
        bool    $excluirDowngrade = false,
        bool    $apenasDowngrade  = false
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->select('s.status, COUNT(s.id) as total')
            ->groupBy('s.status');

        if ($tipo) {
            $qb->andWhere('s.tipo = :tipo')->setParameter('tipo', $tipo);
        }

        if ($excluirDowngrade) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->neq('s.tipo', ':tipoNivel'),
                    $qb->expr()->notLike('s.dados', ':downgradePattern')
                )
            )
            ->setParameter('tipoNivel', Solicitacao::TIPO_NIVEL)
            ->setParameter('downgradePattern', '%"tipoNivel":"downgrade"%');
        }

        if ($apenasDowngrade) {
            $qb->andWhere('s.tipo = :tipoNivel')
               ->andWhere($qb->expr()->like('s.dados', ':downgradePattern'))
               ->setParameter('tipoNivel', Solicitacao::TIPO_NIVEL)
               ->setParameter('downgradePattern', '%"tipoNivel":"downgrade"%');
        }

        $rows = $qb->getQuery()->getResult();

        $map = [
            Solicitacao::STATUS_PENDENTE     => 0,
            Solicitacao::STATUS_EM_ANALISE   => 0,
            Solicitacao::STATUS_EM_ANDAMENTO => 0,
            Solicitacao::STATUS_AGUARDANDO   => 0,
            Solicitacao::STATUS_RESOLVIDA    => 0,
            Solicitacao::STATUS_NEGADA       => 0,
            Solicitacao::STATUS_CANCELADA    => 0,
        ];
        foreach ($rows as $r) {
            $map[$r['status']] = (int) $r['total'];
        }
        return $map;
    }
}
