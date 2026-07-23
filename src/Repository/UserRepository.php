<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Busca um usuário pelo e-mail (case-insensitive no MySQL).
     * Retorna null se não encontrado.
     */
    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /** Retorna todos os usuários com ROLE_ADMIN */
    public function findAdmins(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', '%ROLE_ADMIN%')
            ->setParameter('status', User::STATUS_APPROVED)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna usuários aprovados que devem receber notificação de novos radares na UF informada.
     *
     * Regras de acesso (mesma lógica de User::canAccessUf):
     *   - ROLE_ADMIN            → acesso total (sempre incluído)
     *   - ROLE_GLOBAL_CHAMP     → acesso total (sempre incluído se aprovado)
     *   - allowedUfs IS NULL    → acesso total (incluído se tiver PERM_RADARES ou role admin/global)
     *   - allowedUfs contém UF  → acesso à UF específica (incluído se tiver PERM_RADARES)
     *
     * O JSON do campo allowedUfs é armazenado como array serializado, ex: ["SP","RJ"]
     * Usamos LIKE '%"SP"%' para localizar a sigla dentro do JSON.
     *
     * @return User[]
     */
    public function findApprovedComAcessoUf(string $uf): array
    {
        $ufJson = json_encode($uf); // ex: '"SP"' — com aspas, para bater exato no JSON array

        $qb   = $this->createQueryBuilder('u');
        $expr = $qb->expr();

        return $qb
            ->where('u.status = :status')
            ->andWhere(
                $expr->orX(
                    // ADMINs: acesso irrestrito
                    'u.roles LIKE :admin',

                    // Usuários com PERM_RADARES ou GLOBAL_CHAMP com acesso à UF
                    $expr->andX(
                        $expr->orX(
                            'u.permissions LIKE :perm',
                            'u.roles LIKE :globalChamp'
                        ),
                        $expr->orX(
                            'u.roles LIKE :globalChamp',
                            'u.allowedUfs IS NULL',
                            'u.allowedUfs LIKE :uf'
                        )
                    )
                )
            )
            ->setParameter('status',      User::STATUS_APPROVED)
            ->setParameter('admin',       '%ROLE_ADMIN%')
            ->setParameter('globalChamp', '%ROLE_GLOBAL_CHAMP%')
            ->setParameter('perm',        '%' . User::PERMISSION_RADARES . '%')
            ->setParameter('uf',          '%' . $ufJson . '%')
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
