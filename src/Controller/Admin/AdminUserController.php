<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Solicitacao;
use App\Entity\User;
use App\Message\EnviarEmailConta;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users', name: 'admin_users_')]
class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository         $userRepo,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $emailQueueLogger,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/users/index.html.twig', [
            'pending'    => $this->userRepo->findBy(['status' => User::STATUS_PENDING],  ['createdAt' => 'ASC']),
            'approved'   => $this->userRepo->findBy(['status' => User::STATUS_APPROVED], ['name' => 'ASC']),
            'rejected'   => $this->userRepo->findBy(['status' => User::STATUS_REJECTED], ['createdAt' => 'DESC']),
            'allPerms'   => User::ALL_PERMISSIONS,
            'allUfs'     => User::ALL_UFS,
            'allTipos'   => Solicitacao::TIPOS,
            'champTipos' => User::CHAMP_DOWNGRADE_TIPOS,
        ]);
    }

    // -------------------------------------------------------------------------

    private function buildRoles(Request $request): array
    {
        $roles = ['ROLE_USER'];
        if ($request->request->getBoolean('is_admin')) {
            $roles[] = 'ROLE_ADMIN';
        }
        if ($request->request->getBoolean('is_champ')) {
            $roles[] = 'ROLE_CHAMP';
        }
        return array_unique($roles);
    }

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

        $isChamp = $request->request->getBoolean('is_champ');
        if ($isChamp) {
            $limitDay = $request->request->get('champ_limit_day');
            $user->setChampLimitDay($limitDay !== '' && $limitDay !== null ? (int) $limitDay : null);

            $limitMonth = $request->request->get('champ_limit_month');
            $user->setChampLimitMonth($limitMonth !== '' && $limitMonth !== null ? (int) $limitMonth : null);

            $champTipos = (array) $request->request->all('champ_downgrade_tipos');
            $user->setChampDowngradeTipos(!empty($champTipos) ? $champTipos : null);
        } else {
            $user->setChampLimitDay(null)
                 ->setChampLimitMonth(null)
                 ->setChampDowngradeTipos(null);
        }
    }

    // -------------------------------------------------------------------------

    private function dispatchEmail(string $tipo, int $userId): void
    {
        try {
            $this->bus->dispatch(new EnviarEmailConta($tipo, $userId));
        } catch (\Throwable $e) {
            $this->emailQueueLogger->error('AdminUserController: falha ao despachar email', [
                'tipo'    => $tipo,
                'user_id' => $userId,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }

    // -------------------------------------------------------------------------

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

        $this->dispatchEmail('aprovado', $user->getId());

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

        $this->dispatchEmail('rejeitado', $user->getId());

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

        $this->dispatchEmail('permissoes_atualizadas', $user->getId());

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

        $this->em->createQuery(
            'UPDATE App\Entity\SolicitacaoComentario c SET c.autor = NULL WHERE c.autor = :user'
        )->setParameter('user', $user)->execute();

        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', "Usuário $name removido.");
        return $this->redirectToRoute('admin_users_index');
    }
}
