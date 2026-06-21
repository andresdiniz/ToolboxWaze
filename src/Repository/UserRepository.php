<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        // json_encode("SP") → "SP"  (com aspas, para bater exato dentro do JSON array)
        $ufJson = json_encode($uf);

        return $this->createQueryBuilder('u')
            ->where('u.status = :status')
            ->andWhere(
                // ADMINs: acesso irrestrito, independente de permissão configurada
                'u.roles LIKE :admin

                 OR (
                     -- Usuários com PERM_RADARES ou GLOBAL_CHAMP...
                     (u.permissions LIKE :perm OR u.roles LIKE :globalChamp)
                     AND (
                         -- ...que tenham acesso total (global_champ ou allowedUfs null)
                         u.roles LIKE :globalChamp
                         OR u.allowedUfs IS NULL
                         -- ...ou cujo allowedUfs contenha a UF específica
                         OR u.allowedUfs LIKE :uf
                     )
                 )'
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
