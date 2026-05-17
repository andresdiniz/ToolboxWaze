<?php

namespace App\Repository;

use App\Entity\TipoSolicitacaoConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TipoSolicitacaoConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TipoSolicitacaoConfig::class);
    }

    public function findByTipo(string $tipo): ?TipoSolicitacaoConfig
    {
        return $this->findOneBy(['tipo' => $tipo]);
    }

    public function getOrCreate(string $tipo): TipoSolicitacaoConfig
    {
        $config = $this->findByTipo($tipo);
        if (!$config) {
            $config = (new TipoSolicitacaoConfig())->setTipo($tipo);
            $this->getEntityManager()->persist($config);
            $this->getEntityManager()->flush();
        }
        return $config;
    }
}
