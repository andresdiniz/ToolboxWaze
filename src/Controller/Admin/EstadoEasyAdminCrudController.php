<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BrazilianState;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

#[IsGranted('ROLE_ADMIN')]
final class EstadoEasyAdminCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BrazilianState::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Estado')
            ->setEntityLabelInPlural('Estados')
            ->setPageTitle(Crud::PAGE_INDEX, 'Estados brasileiros')
            ->setPageTitle(Crud::PAGE_EDIT, 'Editar referência de radares')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Detalhes do estado')
            ->setDefaultSort(['uf' => 'ASC'])
            ->setSearchFields(['uf', 'name', 'region'])
            ->setPaginatorPageSize(30);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('uf', 'UF')->setFormTypeOption('disabled', true);
        yield TextField::new('name', 'Nome')->setFormTypeOption('disabled', true);
        yield TextField::new('region', 'Região')->setFormTypeOption('disabled', true);
        yield UrlField::new('linkReferenciaRadares', 'Referência de radares')
            ->setRequired(false)
            ->setHelp('URL HTTP/HTTPS da planilha de referência. Vazio mantém a etapa de referência desabilitada para o estado.')
            ->setFormTypeOption('attr', ['maxlength' => 500])
            ->setFormTypeOption('constraints', [
                new Assert\Length(max: 500),
                new Assert\Url(protocols: ['http', 'https']),
            ]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('uf', 'UF'))
            ->add(TextFilter::new('region', 'Região'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $importar = Action::new('abrirImportacao', 'Importação / logs', 'fa fa-download')
            ->linkToRoute('admin_estados_importar', static fn(BrazilianState $estado): array => ['id' => $estado->getId()]);

        return $actions
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $importar)
            ->add(Crud::PAGE_DETAIL, $importar)
            ->setPermission(Action::INDEX, 'ROLE_ADMIN')
            ->setPermission(Action::DETAIL, 'ROLE_ADMIN')
            ->setPermission(Action::EDIT, 'ROLE_ADMIN')
            ->setPermission('abrirImportacao', 'ROLE_ADMIN');
    }
}
