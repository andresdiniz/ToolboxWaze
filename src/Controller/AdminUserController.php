<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Solicitacao;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/admin/users', name: 'admin_users_')]
class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/users/index.html.twig', [
            'pending'   => $this->userRepo->findBy(['status' => User::STATUS_PENDING],  ['createdAt' => 'ASC']),
            'approved'  => $this->userRepo->findBy(['status' => User::STATUS_APPROVED], ['name' => 'ASC']),
            'rejected'  => $this->userRepo->findBy(['status' => User::STATUS_REJECTED], ['createdAt' => 'DESC']),
            'allPerms'  => User::ALL_PERMISSIONS,
            'allUfs'    => User::ALL_UFS,
            'allTipos'  => Solicitacao::TIPOS,
            'champTipos' => User::CHAMP_DOWNGRADE_TIPOS,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helper: monta array de roles a partir do request
    // -------------------------------------------------------------------------
    private function buildRoles(Request $request): array
    {
        $roles = ['ROLE_USER'];
        if ($request->request->getBoolean('is_admin')) {
            $roles[] = 'ROLE_ADMIN';
        }
        // ROLE_CHAMP é independente de ROLE_ADMIN
        if ($request->request->getBoolean('is_champ')) {
            $roles[] = 'ROLE_CHAMP';
        }
        return array_unique($roles);
    }

    // -------------------------------------------------------------------------
    // Helper: aplica configurações de permissões (comum a approve e updatePermissions)
    // -------------------------------------------------------------------------
    private function applyPermissions(User $user, Request $request): void
    {
        $permissions = $request->request->all('permissions') ?? [];

        $allowedUfs = $request->request->getBoolean('all_ufs')
            ? null
            : array_values(array_filter(
                (array) $request->request->all('allowed_ufs'),
                fn($v) => in_array($v, User::ALL_UFS, true)
              ));

        $solicitacaoTipos = $request->request->getBoolean('all_tipos')
            ? null
            : array_values(array_filter(
                (array) $request->request->all('solicitacao_tipos'),
                fn($v) => array_key_exists($v, Solicitacao::TIPOS)
              ));

        $user->setRoles($this->buildRoles($request))
             ->setPermissions($permissions)
             ->setAllowedUfs($allowedUfs)
             ->setSolicitacaoTipos($solicitacaoTipos);

        // Configurações do perfil Champ (só persistidas se is_champ estiver marcado)
        $isChamp = $request->request->getBoolean('is_champ');
        if ($isChamp) {
            $limitDay = $request->request->get('champ_limit_day');
            $user->setChampLimitDay($limitDay !== '' && $limitDay !== null ? (int) $limitDay : null);

            $limitMonth = $request->request->get('champ_limit_month');
            $user->setChampLimitMonth($limitMonth !== '' && $limitMonth !== null ? (int) $limitMonth : null);

            $champTipos = (array) $request->request->all('champ_downgrade_tipos');
            // null = todos os tipos liberados quando nenhum foi marcado
            $user->setChampDowngradeTipos(!empty($champTipos) ? $champTipos : null);
        } else {
            // Ao remover o perfil Champ, limpa os dados de configuração
            $user->setChampLimitDay(null)
                 ->setChampLimitMonth(null)
                 ->setChampDowngradeTipos(null);
        }
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'])]
    public function approve(User $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('user_action_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_users_index');
        }

        $user->setStatus(User::STATUS_APPROVED)
             ->setApprovedAt(new \DateTimeImmutable());

        $this->applyPermissions($user, $request);

        $this->em->flush();

        $loginUrl = $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $email = (new Email())
                ->from($this->getParameter('app.mail_from'))
                ->to($user->getEmail())
                ->subject('[ToolboxWaze] Seu acesso foi aprovado!')
                ->html($this->renderView('emails/user_approved.html.twig', [
                    'user'       => $user,
                    'allowedUfs' => $user->getAllowedUfs(),
                    'loginUrl'   => $loginUrl,
                ]));
            $this->mailer->send($email);
        } catch (\Throwable) {}

        $ufsLabel = $user->getAllowedUfs() === null ? 'todos os estados' : implode(', ', $user->getAllowedUfs());
        $isChamp  = in_array('ROLE_CHAMP', $user->getRoles(), true);
        $this->addFlash('success', "Usuário {$user->getName()} aprovado. Estados: $ufsLabel" . ($isChamp ? ' | Champ: sim' : '') . '.');
        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(User $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('user_action_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_users_index');
        }

        $user->setStatus(User::STATUS_REJECTED);
        $this->em->flush();

        try {
            $email = (new Email())
                ->from($this->getParameter('app.mail_from'))
                ->to($user->getEmail())
                ->subject('[ToolboxWaze] Atualização sobre sua solicitação de acesso')
                ->html($this->renderView('emails/user_rejected.html.twig', [
                    'user' => $user,
                ]));
            $this->mailer->send($email);
        } catch (\Throwable) {}

        $this->addFlash('warning', "Usuário {$user->getName()} rejeitado.");
        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/permissions', name: 'permissions', methods: ['POST'])]
    public function updatePermissions(User $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('user_perms_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_users_index');
        }

        $this->applyPermissions($user, $request);

        $this->em->flush();

        $loginUrl = $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $email = (new Email())
                ->from($this->getParameter('app.mail_from'))
                ->to($user->getEmail())
                ->subject('[ToolboxWaze] Suas permissões foram atualizadas')
                ->html($this->renderView('emails/user_permissions_updated.html.twig', [
                    'user'       => $user,
                    'allowedUfs' => $user->getAllowedUfs(),
                    'loginUrl'   => $loginUrl,
                ]));
            $this->mailer->send($email);
        } catch (\Throwable) {}

        $ufsLabel = $user->getAllowedUfs() === null ? 'todos os estados' : implode(', ', $user->getAllowedUfs());
        $isChamp  = in_array('ROLE_CHAMP', $user->getRoles(), true);
        $this->addFlash('success', "Permissões de {$user->getName()} atualizadas. Estados: $ufsLabel" . ($isChamp ? ' | Champ: sim' : '') . '.');
        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(User $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('user_delete_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_users_index');
        }

        $name = $user->getName();
        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', "Usuário $name removido.");
        return $this->redirectToRoute('admin_users_index');
    }
}
