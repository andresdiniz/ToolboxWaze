<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        $response = $this->render('admin/dashboard/index.html.twig', [
            'sections' => $this->navigation(),
            'formBuilderAvailable' => $this->isGranted('ROLE_ADMIN4'),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('ToolboxWaze · Administração')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Visão geral', 'fa fa-home');

        foreach ($this->navigation() as $section) {
            yield MenuItem::section($section['label']);
            foreach ($section['items'] as $item) {
                yield MenuItem::linkToRoute(
                    $item['label'],
                    $item['icon'],
                    $item['route'],
                    $item['parameters'] ?? [],
                )->setPermission('ROLE_ADMIN');
            }
        }

        yield MenuItem::section('Site');
        yield MenuItem::linkToRoute('Voltar ao ToolboxWaze', 'fa fa-arrow-left', 'app_dashboard');
    }

    private function navigation(): array
    {
        return [
            [
                'label' => 'Gerenciamento',
                'items' => [
                    ['label' => 'Usuários e permissões', 'icon' => 'fa fa-users', 'route' => 'admin_users_index', 'description' => 'Aprovação de contas, papéis e permissões de acesso.'],
                    ['label' => 'Estados / Radares', 'icon' => 'fa fa-map', 'route' => 'admin_estados_index', 'description' => 'Configuração de estados, fontes e importações de radares.'],
                    ['label' => 'Links Waze', 'icon' => 'fa fa-link', 'route' => 'admin_waze_link_index', 'description' => 'Gerencie os vínculos e referências do Waze.'],
                    ['label' => 'Novo vínculo Waze', 'icon' => 'fa fa-plus', 'route' => 'admin_waze_link_new', 'description' => 'Abra o cadastro existente de um novo vínculo Waze.'],
                ],
            ],
            [
                'label' => 'Formulários',
                'items' => [
                    ['label' => 'Lista de formulários', 'icon' => 'fa fa-list', 'route' => 'admin4_form_builder_index', 'description' => 'Consulte os formulários e suas respostas.'],
                    ['label' => 'Estúdio de formulários', 'icon' => 'fa fa-pen', 'route' => 'admin4_form_studio_novo', 'description' => 'Crie um formulário e configure os seus campos.'],
                    ['label' => 'Publicação na API WME', 'icon' => 'fa fa-broadcast-tower', 'route' => 'admin4_form_api_index', 'description' => 'Controle a publicação no userscript e os papéis autorizados.'],
                ],
            ],
            [
                'label' => 'Solicitações',
                'items' => [
                    ['label' => 'Gerenciar solicitações', 'icon' => 'fa fa-inbox', 'route' => 'solicitacao_hub', 'parameters' => ['aba' => 'gestao'], 'description' => 'Acompanhe e trate os pedidos no fluxo atual de gestão.'],
                ],
            ],
            [
                'label' => 'Monitoramento',
                'items' => [
                    ['label' => 'Auditoria', 'icon' => 'fa fa-clipboard-list', 'route' => 'auditoria_index', 'description' => 'Consulte os registros de auditoria do sistema.'],
                    ['label' => 'Frontend Monitor', 'icon' => 'fa fa-chart-line', 'route' => 'monitoring_dashboard', 'description' => 'Acompanhe o monitoramento do frontend.'],
                    ['label' => 'Requisições suspeitas', 'icon' => 'fa fa-shield-alt', 'route' => 'admin_suspicious_requests_index', 'description' => 'Revise eventos e requisições sinalizados pelo sistema.'],
                ],
            ],
        ];
    }
}
