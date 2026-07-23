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
            'pending'  => $this->userRepo->findBy(['status' => User::STATUS_PENDING],  ['createdAt' => 'ASC']),
            'approved' => $this->userRepo->findBy(['status' => User::STATUS_APPROVED], ['name' => 'ASC']),
            'rejected' => $this->userRepo->findBy(['status' => User::STATUS_REJECTED], ['createdAt' => 'DESC']),
            'allPerms' => User::ALL_PERMISSIONS,
            'allUfs'   => User::ALL_UFS,
            'allTipos' => Solicitacao::TIPOS,
        ]);
    }

    // -------------------------------------------------------------------------

    private function buildRoles(Request $request): array
    {
        $roles = ['ROLE_USER'];
        if ($request->request->getBoolean('is_admin')) {
            $roles[] = 'ROLE_ADMIN';
        }
        if ($request->request->getBoolean('is_global_champ')) {
            $roles[] = 'ROLE_GLOBAL_CHAMP';
            $roles[] = 'ROLE_CHAMP';
        } elseif ($request->request->getBoolean('is_champ')) {
            $roles[] = 'ROLE_CHAMP';
        }
        return array_unique($roles);
    }

    private function applyPermissions(User $user, Request $request): void
    {
        $permissions = $request->request->all('permissions') ?? [];

        $isGlobalChamp = $request->request->getBoolean('is_global_champ');

        $allowedUfs = ($request->request->getBoolean('all_ufs') || $isGlobalChamp)
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

        $ufsLabel      = $user->getAllowedUfs() === null ? 'todos os estados' : implode(', ', $user->getAllowedUfs());
        $champLabel    = $user->isGlobalChamp() ? ' | Global Champ' : ($user->isAnyChamp() ? ' | Champ' : '');
        $this->addFlash('success', "Usuário {$user->getName()} aprovado. Estados: $ufsLabel$champLabel.");
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

        $ufsLabel   = $user->getAllowedUfs() === null ? 'todos os estados' : implode(', ', $user->getAllowedUfs());
        $champLabel = $user->isGlobalChamp() ? ' | Global Champ' : ($user->isAnyChamp() ? ' | Champ' : '');
        $this->addFlash('success', "Permissões de {$user->getName()} atualizadas. Estados: $ufsLabel$champLabel.");
        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Busca manual para ter mensagem de erro clara (evita 404 genérico do EntityValueResolver)
        $user = $this->userRepo->find($id);

        if (!$user) {
            $this->addFlash('error', "Usuário #$id não encontrado. Pode ter sido removido anteriormente.");
            return $this->redirectToRoute('admin_users_index');
        }

        if (!$this->isCsrfTokenValid('user_delete_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_users_index');
        }

        $name = $user->getName();

        try {
            $this->em->wrapInTransaction(function () use ($user): void {
                $userId = $user->getId();

                // Desvincula comentários de solicitação
                $this->em->createQuery(
                    'UPDATE App\Entity\SolicitacaoComentario c SET c.autor = NULL WHERE c.autor = :user'
                )->setParameter('user', $user)->execute();

                // Desvincula solicitações onde o usuário é responsável
                $this->em->createQuery(
                    'UPDATE App\Entity\Solicitacao s SET s.responsavel = NULL WHERE s.responsavel = :user'
                )->setParameter('user', $user)->execute();

                // Desvincula solicitações criadas pelo usuário (preserva o registro)
                $this->em->createQuery(
                    'UPDATE App\Entity\Solicitacao s SET s.criadoPor = NULL WHERE s.criadoPor = :user'
                )->setParameter('user', $user)->execute();

                $this->em->remove($user);
                $this->em->flush();
            });

            $this->addFlash('success', "Usuário $name removido com sucesso.");

        } catch (\Throwable $e) {
            $this->emailQueueLogger->error('AdminUserController: falha ao remover usuário', [
                'user_id' => $user->getId(),
                'error'   => $e->getMessage(),
            ]);
            $this->addFlash('error', "Não foi possível remover $name. Verifique se há registros vinculados e tente novamente.");
        }

        return $this->redirectToRoute('admin_users_index');
    }
}
