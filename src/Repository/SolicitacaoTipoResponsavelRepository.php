<?php

namespace App\Repository;

use App\Entity\SolicitacaoTipoResponsavel;
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

    /** Retorna o registro para o tipo, criando se nao existir. */
    public function findOrCreateByTipo(string $tipo): SolicitacaoTipoResponsavel
    {
        $obj = $this->findOneBy(['tipo' => $tipo]);
        if (!$obj) {
            $obj = new SolicitacaoTipoResponsavel($tipo);
            $this->getEntityManager()->persist($obj);
        }
        return $obj;
    }

    /** Indexado por tipo => SolicitacaoTipoResponsavel */
    public function findAllIndexed(): array
    {
        $result = [];
        foreach ($this->findAll() as $item) {
            $result[$item->getTipo()] = $item;
        }
        return $result;
    }
}
