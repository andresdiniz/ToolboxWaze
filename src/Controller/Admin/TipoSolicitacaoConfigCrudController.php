<?php

namespace App\Controller\Admin;

use App\Entity\Solicitacao;
use App\Entity\TipoSolicitacaoConfig;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\{AssociationField, ChoiceField, IdField};

class TipoSolicitacaoConfigCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return TipoSolicitacaoConfig::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Config de Tipo')
            ->setEntityLabelInPlural('Responsáveis por Tipo de Solicitação')
            ->setPageTitle(Crud::PAGE_INDEX, 'Configurar responsáveis por tipo');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield ChoiceField::new('tipo')->setChoices(array_flip(Solicitacao::TIPOS))->renderExpanded(false);
        yield AssociationField::new('responsaveisDefault', 'Responsáveis')
            ->setFormTypeOptions(['by_reference' => false])->autocomplete();
    }
}
