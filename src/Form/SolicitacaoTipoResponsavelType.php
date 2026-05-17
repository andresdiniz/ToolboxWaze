<?php

namespace App\Form;

use App\Entity\SolicitacaoTipoResponsavel;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityRepository;

class SolicitacaoTipoResponsavelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('responsaveis', EntityType::class, [
            'label'         => false,
            'class'         => User::class,
            'choice_label'  => fn(User $u) => $u->getName() ?: $u->getEmail(),
            'choice_attr'   => fn(User $u) => ['data-email' => $u->getEmail()],
            'multiple'      => true,
            'expanded'      => true,   // checkboxes
            'required'      => false,
            'query_builder' => fn(EntityRepository $er) =>
                $er->createQueryBuilder('u')
                   ->where("u.status = 'approved'")
                   ->orderBy('u.name', 'ASC'),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SolicitacaoTipoResponsavel::class,
        ]);
    }
}
