<?php

namespace App\Repository;

use App\Entity\FormBuilderResposta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FormBuilderRespostaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormBuilderResposta::class);
    }

    public function findByFormulario(int $formId, string $status = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.formulario = :id')
            ->setParameter('id', $formId)
            ->orderBy('r.criadoEm', 'DESC');

        if ($status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }
}
