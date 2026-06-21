<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FormBuilder;
use App\Entity\Solicitacao;
use App\Entity\SolicitacaoHistorico;
use App\Repository\FormBuilderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rota pública para envio de solicitações via FormBuilder dinâmico.
 *
 * URL: /solicitar/{slug}
 * Exemplo: /solicitar/mudanca-de-nivel
 */
#[Route('/solicitar', name: 'solicitar_')]
#[IsGranted('ROLE_USER')]
final class SolicitacaoDinamicaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormBuilderRepository  $formRepo,
    ) {}

    // ── Renderiza e processa o form dinâmico ──────────────────────────────────

    #[Route('/{slug}', name: 'form', methods: ['GET', 'POST'])]
    public function form(string $slug, Request $req): Response
    {
        /** @var FormBuilder|null $formDef */
        $formDef = $this->formRepo->findOneBy(['slug' => $slug, 'ativo' => true]);

        if (!$formDef) {
            throw $this->createNotFoundException('Formulário não encontrado ou inativo.');
        }

        $config  = $formDef->getConfiguracoes() ?? [];
        $campos  = $formDef->getCampos()->toArray();
        $errors  = [];
        $valores = [];

        // Verifica restrição de acesso
        $mostrarPara = $config['mostrar_para'] ?? 'todos';
        if ($mostrarPara === 'visitante') {
            throw $this->createAccessDeniedException();
        }

        // Verifica se expirou
        if (!empty($config['expira_em'])) {
            $expira = new \DateTimeImmutable($config['expira_em']);
            if ($expira < new \DateTimeImmutable()) {
                return $this->render('solicitar/expirado.html.twig', ['form' => $formDef]);
            }
        }

        // Verifica limite de envios do usuário (1 por form por padrão, 0 = ilimitado)
        $limiteEnvios = (int)($config['limite_envios'] ?? 1);
        if ($limiteEnvios > 0) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();
            $jaEnviou = $this->em->getRepository(Solicitacao::class)->count([
                'formulario'        => $formDef,
                'solicitanteEmail'  => $user->getEmail(),
            ]);
            if ($jaEnviou >= $limiteEnvios) {
                return $this->render('solicitar/limite.html.twig', ['form' => $formDef]);
            }
        }

        if ($req->isMethod('POST')) {
            $post    = $req->request->all();
            $valores = $post['campos'] ?? [];

            // Valida campos obrigatórios
            foreach ($campos as $campo) {
                if ($campo->isObrigatorio() && empty($valores[$campo->getChave()])) {
                    $errors[$campo->getChave()] = 'Campo obrigatório.';
                }
            }

            if (empty($errors)) {
                /** @var \App\Entity\User $user */
                $user = $this->getUser();

                $sol = new Solicitacao();
                $sol->setFormulario($formDef)
                    ->setTipo($formDef->getSlug())
                    ->setStatus(Solicitacao::STATUS_PENDENTE)
                    ->setSolicitanteNome($user->getNome() ?? $user->getEmail())
                    ->setSolicitanteUsuario($user->getWazeUsername() ?? $user->getEmail())
                    ->setSolicitanteEmail($user->getEmail())
                    ->setDadosDinamicos($valores);

                // Histórico inicial
                $hist = new SolicitacaoHistorico();
                $hist->setSolicitacao($sol)
                     ->setAutor($user)
                     ->setAcao('criada')
                     ->setDescricao('Solicitação criada via formulário "' . $formDef->getNome() . '".');
                $sol->addHistorico($hist);

                $this->em->persist($sol);
                $this->em->flush();

                // Redireciona para URL customizada ou mensagem padrão
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
            'campos'  => $campos,
            'valores' => $valores,
            'errors'  => $errors,
        ]);
    }

    // ── Listagem das solicitações do próprio usuário ──────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $forms = $this->formRepo->findBy(['ativo' => true], ['criadoEm' => 'DESC']);

        $minhas = $this->em->getRepository(Solicitacao::class)->findBy(
            ['solicitanteEmail' => $user->getEmail()],
            ['criadaEm' => 'DESC'],
            20
        );

        return $this->render('solicitar/index.html.twig', [
            'forms'   => $forms,
            'minhas'  => $minhas,
        ]);
    }
}
