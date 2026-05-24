<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\ImportRadarCommand;
use App\Entity\BrazilianState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/estados', name: 'admin_estados_')]
#[IsGranted('ROLE_ADMIN')]
final class BrazilianStateCrudController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportRadarCommand     $importCommand,
    ) {}

    // =========================================================================
    // LIST
    // =========================================================================

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $states = $this->em
            ->getRepository(BrazilianState::class)
            ->findBy([], ['uf' => 'ASC']);

        return $this->render('admin/estados/index.html.twig', [
            'states' => $states,
        ]);
    }

    // =========================================================================
    // EDIT
    // =========================================================================

    #[Route('/{id}/editar', name: 'edit', requirements: ['id' => '\\d+'])]
    public function edit(int $id, Request $req): Response
    {
        $state = $this->em->find(BrazilianState::class, $id);

        if (!$state) {
            throw $this->createNotFoundException('Estado não encontrado.');
        }

        if ($req->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_state_' . $id, $req->request->get('_token'))) {
                $this->addFlash('error', 'Token inválido.');
                return $this->redirectToRoute('admin_estados_index');
            }

            $linkBase      = trim((string) $req->request->get('link_base_radares', ''));
            $linkReferencia = trim((string) $req->request->get('link_referencia_radares', ''));

            $state->setLinkBaseRadares($linkBase ?: null);
            $state->setLinkReferenciaRadares($linkReferencia ?: null);

            $this->em->flush();

            $this->addFlash('success', sprintf('Estado %s atualizado com sucesso.', $state->getUf()));

            return $this->redirectToRoute('admin_estados_index');
        }

        return $this->render('admin/estados/edit.html.twig', [
            'state' => $state,
        ]);
    }

    // =========================================================================
    // FORÇAR IMPORTAÇÃO
    // =========================================================================

    #[Route('/{id}/importar', name: 'importar', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function importar(int $id, Request $req): Response
    {
        $state = $this->em->find(BrazilianState::class, $id);

        if (!$state) {
            throw $this->createNotFoundException('Estado não encontrado.');
        }

        if (!$this->isCsrfTokenValid('importar_state_' . $id, $req->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_estados_index');
        }

        $skipWaze = (bool) $req->request->get('skip_waze', false);
        $uf       = $state->getUf();

        try {
            $input  = new ArrayInput([
                '--uf'   => [$uf],
                '--env'  => 'prod',
            ] + ($skipWaze ? ['--skip-waze' => true] : []));

            $output = new BufferedOutput();

            $input->setInteractive(false);
            $this->importCommand->run($input, $output);

            $this->addFlash('success', sprintf(
                'Importação de %s concluída.%s',
                $uf,
                $skipWaze ? ' (links Waze pulados)' : ''
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Erro ao importar %s: %s', $uf, $e->getMessage()));
        }

        return $this->redirectToRoute('admin_estados_index');
    }
}
