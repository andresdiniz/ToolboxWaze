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
     * Retorna mapa UF => URL para estados com link_referencia_radares preenchido.
     * Usado na Etapa 2 do app:import-radares para importar links Waze.
     *
     * @return array<string, string>  ['MG' => 'https://...', ...]
     */
    public function findLinkMapReferencia(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.uf', 's.linkReferenciaRadares')
            ->where('s.linkReferenciaRadares IS NOT NULL')
            ->orderBy('s.uf', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['uf']] = $row['linkReferenciaRadares'];
        }
        return $map;
    }

    /** Retorna o estado completo pela sigla UF (case-insensitive). */
    public function findByUf(string $uf): ?BrazilianState
    {
        return $this->findOneBy(['uf' => strtoupper($uf)]);
    }
}
