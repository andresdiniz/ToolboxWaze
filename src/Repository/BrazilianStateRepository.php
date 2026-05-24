<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BrazilianState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrazilianState>
 */
class BrazilianStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrazilianState::class);
    }

    /** Retorna todas as siglas UF ordenadas alfabeticamente. */
    public function findAllUfs(): array
    {
        return $this->createQueryBuilder('s')
            ->select('s.uf')
            ->orderBy('s.uf', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Retorna mapa ['UF' => 'https://...'] para todos os estados
     * que possuem link_base_radares preenchido.
     *
     * @return array<string, string>  ['MG' => 'https://...', ...]
     */
    public function findLinkMapRadares(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.uf', 's.linkBaseRadares')
            ->where('s.linkBaseRadares IS NOT NULL')
            ->orderBy('s.uf', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['uf']] = $row['linkBaseRadares'];
        }
        return $map;
    }

    /** Retorna o estado completo pela sigla UF (case-insensitive). */
    public function findByUf(string $uf): ?BrazilianState
    {
        return $this->findOneBy(['uf' => strtoupper($uf)]);
    }
}
