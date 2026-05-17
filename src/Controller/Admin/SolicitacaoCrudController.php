<?php

namespace App\Controller\Admin;

use App\Entity\Solicitacao;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud, Filters};
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\{
    ChoiceField, DateTimeField, IdField, TextField, TextareaField, AssociationField
};
use EasyCorp\Bundle\EasyAdminBundle\Filter\{ChoiceFilter, EntityFilter};

class SolicitacaoCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return Solicitacao::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Solicitação')
            ->setEntityLabelInPlural('Solicitações')
            ->setDefaultSort(['criadaEm' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield ChoiceField::new('tipo')->setChoices(array_flip(Solicitacao::TIPOS));
        yield ChoiceField::new('status')->setChoices([
            'Pendente'  => Solicitacao::STATUS_PENDENTE,
            'Resolvida' => Solicitacao::STATUS_RESOLVIDA,
            'Cancelada' => Solicitacao::STATUS_CANCELADA,
        ])->renderAsBadges([
            Solicitacao::STATUS_PENDENTE   => 'warning',
            Solicitacao::STATUS_RESOLVIDA  => 'success',
            Solicitacao::STATUS_CANCELADA  => 'danger',
        ]);
        yield TextField::new('solicitanteUsuario', 'Usuário');
        yield TextField::new('solicitanteEmail', 'E-mail')->onlyOnDetail();
        yield TextField::new('estado', 'UF');
        yield AssociationField::new('responsaveis', 'Responsáveis')->onlyOnDetail();
        yield AssociationField::new('resolvidaPor', 'Resolvida por')->onlyOnDetail();
        yield DateTimeField::new('criadaEm', 'Criada em');
        yield DateTimeField::new('resolvidaEm', 'Resolvida em')->onlyOnDetail();
        yield TextareaField::new('notaResolucao', 'Nota')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('tipo')->setChoices(array_flip(Solicitacao::TIPOS)))
            ->add(ChoiceFilter::new('status')->setChoices([
                'Pendente'  => Solicitacao::STATUS_PENDENTE,
                'Resolvida' => Solicitacao::STATUS_RESOLVIDA,
            ]))
            ->add(EntityFilter::new('responsaveis'));
    }
}
