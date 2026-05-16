<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RadarWazeLink;
use App\Entity\RadarWazeLinkLog;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RadarWazeLinkCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getEntityFqcn(): string
    {
        return RadarWazeLink::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Link Waze')
            ->setEntityLabelInPlural('Links Waze')
            ->setPageTitle(Crud::PAGE_INDEX, 'Links do Editor Waze')
            ->setPageTitle(Crud::PAGE_NEW, 'Adicionar Link Waze')
            ->setPageTitle(Crud::PAGE_EDIT, 'Editar Link Waze')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Detalhe do Link Waze')
            ->setDefaultSort(['insertedAt' => 'DESC'])
            ->setSearchFields(['wazeLink', 'permanentHazardId', 'observacao'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield AssociationField::new('radarMedidor', 'Radar')
            ->setRequired(true)
            ->autocomplete();

        yield UrlField::new('wazeLink', 'Link do Editor Waze')
            ->setHelp(
                'Cole a URL do editor Waze. Deve conter o parâmetro '
                . '<code>permanentHazards=&lt;número&gt;</code>.'
            )
            ->setMaxLength(1000)
            ->hideOnIndex();

        // Na listagem mostra um link clicável curto
        yield TextField::new('wazeLink', 'Link Waze')
            ->formatValue(static function (string $url): string {
                $id = RadarWazeLink::extractPermanentHazardId($url);
                return sprintf(
                    '<a href="%s" target="_blank" rel="noopener">🗺 #%s</a>',
                    htmlspecialchars($url),
                    $id ?? '?'
                );
            })
            ->renderAsHtml()
            ->onlyOnIndex();

        yield IntegerField::new('permanentHazardId', 'ID Hazard')
            ->setDisabled()
            ->hideOnForm();

        yield TextareaField::new('observacao', 'Observação')
            ->setNumOfRows(3)
            ->setRequired(false);

        yield AssociationField::new('insertedBy', 'Inserido por')->hideOnForm();
        yield DateTimeField::new('insertedAt', 'Inserido em')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->hideOnForm();

        yield AssociationField::new('updatedBy', 'Atualizado por')
            ->hideOnForm()
            ->hideOnIndex();
        yield DateTimeField::new('updatedAt', 'Atualizado em')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        $historico = Action::new('historico', 'Histórico', 'fa fa-history')
            ->linkToRoute('admin_waze_link_historico', fn(RadarWazeLink $l) => ['id' => $l->getId()])
            ->addCssClass('btn btn-sm btn-info');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $historico)
            ->add(Crud::PAGE_DETAIL, $historico);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('radarMedidor', 'Radar'));
    }

    // -------------------------------------------------------------------------
    // Hook: preenche insertedBy/insertedAt no create e grava log no update
    // -------------------------------------------------------------------------

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        /** @var RadarWazeLink $entityInstance */
        $user = $this->security->getUser();
        $entityInstance->setInsertedBy($user);
        $entityInstance->setInsertedAt(new \DateTimeImmutable());

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        /** @var RadarWazeLink $entityInstance */
        $user  = $this->security->getUser();
        $now   = new \DateTimeImmutable();
        $uow   = $entityManager->getUnitOfWork();
        $uow->computeChangeSets();
        $changes = $uow->getEntityChangeSet($entityInstance);

        $entityInstance->setUpdatedBy($user);
        $entityInstance->setUpdatedAt($now);

        foreach ($changes as $campo => $valores) {
            [$anterior, $novo] = $valores;
            $log = RadarWazeLinkLog::create(
                $entityInstance,
                $user,
                $campo,
                $anterior !== null ? (string) $anterior : null,
                $novo      !== null ? (string) $novo     : null,
            );
            $entityManager->persist($log);
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    // -------------------------------------------------------------------------
    // Rota dedicada de histórico
    // -------------------------------------------------------------------------

    #[Route('/admin/radar-waze-link/{id}/historico', name: 'admin_waze_link_historico')]
    public function historico(int $id, Request $request): Response
    {
        $link = $this->em->find(RadarWazeLink::class, $id);

        if (!$link) {
            throw $this->createNotFoundException('Link não encontrado.');
        }

        $logs = $this->em->getRepository(RadarWazeLinkLog::class)
            ->findBy(['radarWazeLink' => $link], ['changedAt' => 'DESC']);

        return $this->render('admin/radar_waze_link/historico.html.twig', [
            'link' => $link,
            'logs' => $logs,
        ]);
    }
}
