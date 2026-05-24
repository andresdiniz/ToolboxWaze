<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RadarManual;
use App\Form\RadarManualType;
use App\Repository\RadarManualRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/radar/manual')]
#[IsGranted('ROLE_USER')]
class RadarManualController extends AbstractController
{
    #[Route('', name: 'radar_manual_lista', methods: ['GET'])]
    public function lista(
        RadarManualRepository $repo,
        Request $request,
    ): Response {
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 25;

        return $this->render('radar/manual_lista.html.twig', [
            'radares'   => $repo->findPaginado($page, $perPage),
            'total'     => $repo->countTotal(),
            'pendentes' => $repo->countPendentes(),
            'page'      => $page,
            'perPage'   => $perPage,
        ]);
    }

    #[Route('/novo', name: 'radar_manual_novo', methods: ['GET', 'POST'])]
    public function novo(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $radar = new RadarManual();
        $form  = $this->createForm(RadarManualType::class, $radar);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $radar->recalcIdentityHash();
            $radar->setCriadoPor($this->getUser());

            // Verifica se já existe um RadarManual com o mesmo identity_hash
            $existing = $em->getRepository(RadarManual::class)
                ->findOneBy(['identityHash' => $radar->getIdentityHash()]);

            if ($existing !== null) {
                $this->addFlash('warning', sprintf(
                    'Já existe um radar com este local/tipo cadastrado (ID #%d, status: %s).',
                    $existing->getId(),
                    $existing->getStatus()
                ));

                return $this->render('radar/manual_novo.html.twig', [
                    'form'  => $form,
                    'radar' => null,
                ]);
            }

            $em->persist($radar);
            $em->flush();

            $this->addFlash('success', sprintf(
                'Radar #%d cadastrado com sucesso! Ele ficará com status <strong>pendente</strong> até aparecer na fonte oficial.',
                $radar->getId()
            ));

            return $this->redirectToRoute('radar_manual_lista');
        }

        return $this->render('radar/manual_novo.html.twig', [
            'form'  => $form,
            'radar' => null,
        ]);
    }

    #[Route('/{id}', name: 'radar_manual_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(RadarManual $radar): Response
    {
        return $this->render('radar/manual_show.html.twig', [
            'radar' => $radar,
        ]);
    }

    #[Route('/{id}/excluir', name: 'radar_manual_excluir', methods: ['POST'], requirements: ['id' => '\\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function excluir(
        RadarManual $radar,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('excluir_radar_manual_' . $radar->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('radar_manual_lista');
        }

        if ($radar->isMesclado()) {
            $this->addFlash('warning', 'Radares já mesclados não podem ser excluídos (auditoria).');
            return $this->redirectToRoute('radar_manual_lista');
        }

        $em->remove($radar);
        $em->flush();

        $this->addFlash('success', 'Radar manual removido.');
        return $this->redirectToRoute('radar_manual_lista');
    }
}
