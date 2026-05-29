<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SuspiciousRequest;
use App\Repository\SuspiciousRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/suspicious-requests', name: 'admin_suspicious_requests_')]
class AdminSuspiciousRequestController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SuspiciousRequestRepository $repo,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $action = $request->query->get('action', ''); // 'block', 'flag' ou ''
        $ip     = trim((string) $request->query->get('ip', ''));
        $page   = max(1, (int) $request->query->get('page', 1));
        $limit  = 50;

        $qb = $this->repo->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC');

        if ($action !== '') {
            $qb->andWhere('s.action = :action')->setParameter('action', $action);
        }
        if ($ip !== '') {
            $qb->andWhere('s.ip LIKE :ip')->setParameter('ip', '%' . $ip . '%');
        }

        $total   = (clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $records = $qb->setFirstResult(($page - 1) * $limit)
                      ->setMaxResults($limit)
                      ->getQuery()
                      ->getResult();

        $blockedIps = $this->repo->findBlockedIps();

        return $this->render('admin/suspicious_requests/index.html.twig', [
            'records'    => $records,
            'blockedIps' => $blockedIps,
            'total'      => $total,
            'page'       => $page,
            'pages'      => (int) ceil($total / $limit),
            'filterAction' => $action,
            'filterIp'     => $ip,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(SuspiciousRequest $record, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('suspicious_delete_' . $record->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_suspicious_requests_index');
        }

        $this->em->remove($record);
        $this->em->flush();

        $this->addFlash('success', 'Registro removido.');
        return $this->redirectToRoute('admin_suspicious_requests_index');
    }

    #[Route('/clear-old', name: 'clear_old', methods: ['POST'])]
    public function clearOld(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('suspicious_clear_old', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_suspicious_requests_index');
        }

        $days = max(1, (int) $request->request->get('days', 30));
        $since = new \DateTimeImmutable("-{$days} days");

        $deleted = $this->em->createQuery(
            'DELETE FROM App\Entity\SuspiciousRequest s WHERE s.createdAt < :since'
        )->setParameter('since', $since)->execute();

        $this->addFlash('success', "$deleted registros anteriores a $days dias removidos.");
        return $this->redirectToRoute('admin_suspicious_requests_index');
    }
}
