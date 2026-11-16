<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FormBuilder;
use App\Entity\Solicitacao;
use App\Entity\SolicitacaoHistorico;
use App\Entity\User;
use App\Repository\FormBuilderRepository;
use App\Service\SolicitacaoIntervaloService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/solicitar', name: 'solicitar_')]
#[IsGranted('ROLE_USER')]
final class SolicitacaoDinamicaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormBuilderRepository $formRepo,
        private readonly SolicitacaoIntervaloService $intervalo,
    ) {}

    #[Route('/{slug}', name: 'form', methods: ['GET', 'POST'])]
    public function form(string $slug, Request $req): Response
    {
        /** @var FormBuilder|null $formDef */
        $formDef = $this->formRepo->findOneBy(['slug' => $slug, 'ativo' => true]);
        if (!$formDef) {
            throw $this->createNotFoundException('Formulário não encontrado ou inativo.');
        }
        $config = $formDef->getConfiguracoes() ?? [];
        $campos = $formDef->getCampos()->toArray();
        $errors = [];
        $valores = [];
        if (($config['mostrar_para'] ?? 'todos') === 'visitante') {
            throw $this->createAccessDeniedException();
        }
        if (!empty($config['expira_em'])) {
            $expira = new \DateTimeImmutable($config['expira_em']);
            if ($expira < new \DateTimeImmutable()) {
                return $this->render('solicitar/expirado.html.twig', ['form' => $formDef]);
            }
        }
        /** @var User $user */
        $user = $this->getUser();
        if ($req->isMethod('POST')) {
            $post = $req->request->all();
            $valores = is_array($post['campos'] ?? null) ? $post['campos'] : [];
            foreach ($campos as $campo) {
                if ($campo->isObrigatorio() && empty($valores[$campo->getChave()])) {
                    $errors[$campo->getChave()] = 'Campo obrigatório.';
                }
            }
            if ($errors === []) {
                $database = $this->em->getConnection();
                $database->beginTransaction();
                try {
                    $this->intervalo->lock($user);
                    $intervalo = $this->intervalo->status($user, $formDef);
                    if ($intervalo['aguardarSegundos'] > 0) {
                        $database->rollBack();
                        $message = 'Aguarde ' . $intervalo['aguardarSegundos']
                            . ' segundos para enviar este formulário novamente.';
                        $this->addFlash('warning', $message);
                        $response = $this->render('solicitar/form.html.twig', [
                            'formDef' => $formDef,
                            'campos' => $campos,
                            'valores' => $valores,
                            'errors' => $errors,
                            'intervaloEnvio' => $intervalo,
                        ]);
                        $response->setStatusCode(429);
                        $response->headers->set('Retry-After', (string) $intervalo['aguardarSegundos']);
                        $response->headers->set('Cache-Control', 'private, no-store');
                        return $response;
                    }
                    $sol = new Solicitacao();
                    $sol->setFormulario($formDef)
                        ->setTipo($formDef->getSlug())
                        ->setStatus(Solicitacao::STATUS_PENDENTE)
                        ->setSolicitanteNome($user->getNome() ?? $user->getEmail())
                        ->setSolicitanteUsuario($user->getWazeUsername() ?? $user->getEmail())
                        ->setSolicitanteEmail($user->getEmail())
                        ->setDadosDinamicos($valores);
                    $hist = new SolicitacaoHistorico();
                    $hist->setSolicitacao($sol)
                        ->setAutor($user)
                        ->setAcao('criada')
                        ->setDescricao('Solicitação criada via formulário "' . $formDef->getNome() . '".');
                    $sol->addHistorico($hist);
                    $this->em->persist($sol);
                    $this->em->flush();
                    $database->commit();
                } catch (\Throwable $exception) {
                    if ($database->isTransactionActive()) {
                        $database->rollBack();
                    }
                    throw $exception;
                }
                $redirectUrl = $config['redirect_url'] ?? null;
                if ($redirectUrl) {
                    return $this->redirect($redirectUrl);
                }
                $this->addFlash('success', $config['success_message'] ?? 'Solicitação enviada com sucesso!');
                return $this->redirectToRoute('solicitar_form', ['slug' => $slug]);
            }
        }
        return $this->render('solicitar/form.html.twig', [
            'formDef' => $formDef,
            'campos' => $campos,
            'valores' => $valores,
            'errors' => $errors,
        ]);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $forms = $this->formRepo->findBy(['ativo' => true], ['criadoEm' => 'DESC']);
        $minhas = $this->em->getRepository(Solicitacao::class)->findBy(
            ['solicitanteEmail' => $user->getEmail()],
            ['criadaEm' => 'DESC'],
            20
        );
        return $this->render('solicitar/index.html.twig', [
            'forms' => $forms,
            'minhas' => $minhas,
        ]);
    }
}
