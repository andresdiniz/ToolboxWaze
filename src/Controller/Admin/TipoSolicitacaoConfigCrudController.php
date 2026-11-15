<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FormBuilder;
use App\Entity\Solicitacao;
use App\Entity\TipoSolicitacaoConfig;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[IsGranted('ROLE_ADMIN')]
final class TipoSolicitacaoConfigCrudController extends AbstractCrudController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public static function getEntityFqcn(): string
    {
        return TipoSolicitacaoConfig::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Configuração de responsáveis')
            ->setEntityLabelInPlural('Responsáveis padrão por tipo')
            ->setPageTitle(Crud::PAGE_INDEX, 'Responsáveis padrão por tipo de solicitação')
            ->setPageTitle(Crud::PAGE_NEW, 'Cadastrar responsáveis padrão')
            ->setPageTitle(Crud::PAGE_EDIT, 'Editar responsáveis padrão')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Detalhes da configuração')
            ->setDefaultSort(['tipo' => 'ASC'])
            ->setSearchFields(['tipo', 'responsaveisDefault.name', 'responsaveisDefault.email'])
            ->setPaginatorPageSize(30);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield ChoiceField::new('tipo', 'Tipo de solicitação')
            ->setChoices($this->choices())
            ->setRequired(true)
            ->setFormTypeOption('disabled', $pageName === Crud::PAGE_EDIT)
            ->setFormTypeOption('constraints', [
                new Assert\NotBlank(),
                new Assert\Length(max: 64),
                new Assert\Callback(function (mixed $value, ExecutionContextInterface $context): void {
                    if (!is_string($value) || $value === '') return;
                    $current = $context->getRoot()->getData();
                    if (!$current instanceof TipoSolicitacaoConfig) return;
                    $existing = $this->em->getRepository(TipoSolicitacaoConfig::class)->findOneBy(['tipo' => $value]);
                    if ($existing && $existing->getId() !== $current->getId()) {
                        $context->buildViolation('Já existe uma configuração para esse tipo. Edite a configuração existente.')->addViolation();
                    }
                }),
            ])
            ->setHelp('O tipo é estável após o cadastro. A lista inclui tipos legados e formulários cadastrados no banco.');

        yield AssociationField::new('responsaveisDefault', 'Responsáveis padrão')
            ->onlyOnForms()
            ->setRequired(false)
            ->setFormTypeOption('by_reference', false)
            ->setFormTypeOption('choice_label', static fn(User $user): string => ($user->getName() ?: $user->getEmail()).' <'.$user->getEmail().'>')
            ->setHelp('Vincula responsáveis padrão a novos pedidos. Não concede papéis ou permissões e não altera solicitações já criadas.');

        yield TextField::new('tipoLabel', 'Responsáveis cadastrados')
            ->hideOnForm()
            ->setSortable(false)
            ->formatValue(static function (mixed $value, TipoSolicitacaoConfig $config): string {
                $names = [];
                foreach ($config->getResponsaveisDefault() as $user) {
                    $names[] = ($user->getName() ?: $user->getEmail()).' <'.$user->getEmail().'>';
                }
                return $names ? implode(', ', $names) : 'Nenhum responsável padrão';
            });
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(TextFilter::new('tipo', 'Tipo de solicitação'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::INDEX, 'ROLE_ADMIN')
            ->setPermission(Action::DETAIL, 'ROLE_ADMIN')
            ->setPermission(Action::NEW, 'ROLE_ADMIN')
            ->setPermission(Action::EDIT, 'ROLE_ADMIN');
    }

    private function choices(): array
    {
        $types = Solicitacao::TIPOS;
        foreach ($this->em->getRepository(FormBuilder::class)->findBy([], ['nome' => 'ASC']) as $form) {
            if (strlen($form->getSlug()) <= 64) $types[$form->getSlug()] = $form->getNome();
        }
        foreach ($this->em->getRepository(TipoSolicitacaoConfig::class)->findAll() as $config) {
            $types[$config->getTipo()] ??= $config->getTipo();
        }
        $choices = [];
        foreach ($types as $slug => $name) $choices[$name.' ('.$slug.')'] = $slug;
        asort($choices);
        return $choices;
    }
}
