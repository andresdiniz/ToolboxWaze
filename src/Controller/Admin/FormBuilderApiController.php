<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FormBuilder;
use App\Repository\FormBuilderRepository;
use App\Service\WmeFormularioService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin4/form-api', name: 'admin4_form_api_')]
#[IsGranted('ROLE_ADMIN')]
final class FormBuilderApiController extends AbstractController
{
    public function __construct(private readonly WmeFormularioService $schemas) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(FormBuilderRepository $repo): Response
    {
        $rows = [];
        foreach ($repo->findBy([], ['nome' => 'ASC']) as $form) {
            $config = $form->getConfiguracoes() ?? [];
            $roles = $config['wme_roles'] ?? [];
            $rows[] = [
                'formulario' => $form,
                'publicado' => ($config['wme_api_enabled'] ?? false) === true,
                'roles' => is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [],
                'bloqueios' => $this->blockers($form),
            ];
        }
        $response = $this->render('admin4/form_builder/api.html.twig', ['rows' => $rows]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/{id}/salvar', name: 'salvar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(int $id, Request $request, FormBuilderRepository $repo, EntityManagerInterface $em): Response
    {
        $form = $repo->find($id) ?? throw $this->createNotFoundException();
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('form_api_'.$id, $token)) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $enabled = $request->request->get('wme_api_enabled') === '1';
        $raw = $request->request->get('wme_roles', '');
        if (!is_string($raw) || strlen($raw) > 2000) {
            $this->addFlash('danger', 'Lista de papéis inválida.');
            return $this->redirectToRoute('admin4_form_api_index');
        }
        $roles = array_values(array_unique(preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        foreach ($roles as $role) {
            if (!preg_match('/^ROLE_[A-Z0-9_]{1,80}$/D', $role)) {
                $this->addFlash('danger', 'Papel inválido: '.$role);
                return $this->redirectToRoute('admin4_form_api_index');
            }
        }
        if ($enabled && ($errors = $this->blockers($form))) {
            $this->addFlash('danger', 'Publicação bloqueada: '.implode(' ', $errors));
            return $this->redirectToRoute('admin4_form_api_index');
        }
        $config = $form->getConfiguracoes() ?? [];
        $config['wme_api_enabled'] = $enabled;
        $config['wme_roles'] = $roles;
        $form->setConfiguracoes($config)->setAtualizadoEm(new \DateTimeImmutable());
        $em->flush();
        $this->addFlash('success', $form->getNome().($enabled ? ': publicado na API WME.' : ': removido da API WME.'));
        return $this->redirectToRoute('admin4_form_api_index');
    }

    /** Verificações de publicação; não substituem a autorização da API por requisição. */
    private function blockers(FormBuilder $form): array
    {
        $errors = [];
        $config = $form->getConfiguracoes() ?? [];
        if (!$form->isAtivo()) $errors[] = 'Ative o formulário antes de publicar.';
        if ($form->getCampos()->isEmpty()) $errors[] = 'Cadastre ao menos um campo.';
        if (($config['mostrar_para'] ?? 'todos') === 'visitante') $errors[] = 'Visibilidade somente para visitantes não é compatível com a API autenticada.';
        if (!empty($config['expira_em'])) {
            try {
                if (!is_string($config['expira_em'])) throw new \InvalidArgumentException();
                if (new \DateTimeImmutable($config['expira_em']) < new \DateTimeImmutable()) $errors[] = 'O formulário está expirado.';
            } catch (\Exception) {
                $errors[] = 'Data de expiração inválida.';
            }
        }
        if (!empty($config['unificacao_campos_pendente'])) $errors[] = 'Unificação de regras de segurança ainda pendente.';
        foreach ($form->getCampos() as $field) {
            $options = $field->getOpcoes() ?? [];
            foreach (['roles', 'permissoesOpcoes', 'variantes', 'requerVisitaLink'] as $key) {
                if (!empty($options[$key])) $errors[] = $field->getChave().': regra '.$key.' ainda não suportada pela API.';
            }
            if (in_array($field->getTipo(), ['select', 'radio'], true)) {
                $choices = $options['opcoes'] ?? [];
                if (!is_array($choices) || !$choices) {
                    $errors[] = $field->getChave().': cadastre opções de escolha.';
                } else {
                    $values = [];
                    foreach ($choices as $key => $label) {
                        if (is_array($label)) {
                            $value = $label['value'] ?? null;
                            $text = $label['label'] ?? null;
                        } else {
                            $value = is_int($key) ? $label : $key;
                            $text = $label;
                        }
                        if (!is_scalar($value) || !is_scalar($text) || (string) $value === '' || isset($values[(string) $value])) {
                            $errors[] = $field->getChave().': opções inválidas ou duplicadas.';
                            break;
                        }
                        $values[(string) $value] = true;
                    }
                }
            }
        }
        if (!$errors) {
            try {
                $this->schemas->schema($form);
            } catch (HttpException $e) {
                $errors[] = $e->getMessage();
            } catch (\Throwable) {
                $errors[] = 'Não foi possível validar o schema. Revise as configurações dos campos.';
            }
        }
        return array_values(array_unique($errors));
    }
}
