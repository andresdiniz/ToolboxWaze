<?php

namespace App\Repository;

use App\Entity\FormBuilder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FormBuilderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormBuilder::class);
    }

    public function findBySlugAtivo(string $slug): ?FormBuilder
    {
        return $this->findOneBy(['slug' => $slug, 'ativo' => true]);
    }

    public function findAllAtivos(): array
    {
        return $this->findBy(['ativo' => true], ['nome' => 'ASC']);
    }
}
