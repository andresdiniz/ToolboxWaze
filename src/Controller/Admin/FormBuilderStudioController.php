<?php

declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\FormBuilder;
use App\Entity\FormBuilderCampo;
use App\Repository\FormBuilderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin4/form-studio', name: 'admin4_form_studio_')]
#[IsGranted('ROLE_ADMIN4')]
final class FormBuilderStudioController extends AbstractController
{
    #[Route('/novo', name: 'novo', methods: ['GET', 'POST'])]
    #[Route('/{id}/editar', name: 'editar', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editor(Request $request, FormBuilderRepository $repo, EntityManagerInterface $em, ?int $id = null): Response
    {
        $entity = $id === null ? new FormBuilder() : ($repo->find($id) ?? throw $this->createNotFoundException());
        $definition = [
            'nome' => $entity->getNome(), 'slug' => $entity->getSlug(),
            'descricao' => $entity->getDescricao(), 'ativo' => $entity->isAtivo(),
            'campos' => array_map(static fn(FormBuilderCampo $c): array => [
                'chave' => $c->getChave(), 'label' => $c->getLabel(), 'tipo' => $c->getTipo(),
                'obrigatorio' => $c->isObrigatorio(), 'placeholder' => $c->getPlaceholder(),
                'ajuda' => $c->getAjuda(), 'valor_padrao' => $c->getValorPadrao(), 'opcoes' => $c->getOpcoes() ?? new \stdClass(),
            ], $entity->getCampos()->toArray()),
        ];
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('form_studio_'.($id ?? 'new'), $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF invalido.');
            }
            try {
                $raw = $request->request->get('definition', '');
                if (!is_string($raw) || strlen($raw) > 1048576) throw new \InvalidArgumentException('Definicao excede 1 MB.');
                $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                $this->validate($data);
                $definition = $data;
                if ($id !== null && $data['slug'] !== $entity->getSlug()) throw new \InvalidArgumentException('O slug de um formulario existente e imutavel neste editor.');
                $other = $repo->findOneBy(['slug' => $data['slug']]);
                if ($other && $other->getId() !== $entity->getId()) throw new \InvalidArgumentException('Slug ja utilizado.');
                $existing = [];
                foreach ($entity->getCampos() as $field) {
                    if (isset($existing[$field->getChave()])) throw new \InvalidArgumentException('Existem chaves duplicadas no banco.');
                    $existing[$field->getChave()] = $field;
                }
                $incoming = array_column($data['campos'], null, 'chave');
                foreach ($existing as $key => $field) {
                    if (!isset($incoming[$key])) throw new \InvalidArgumentException('Remocao ou renomeacao de campos existentes requer migracao explicita: '.$key);
                }
                $entity->setNome($data['nome'])->setSlug($data['slug'])->setDescricao($data['descricao'] ?: null)->setAtivo($data['ativo']);
                $entity->setAtualizadoEm(new \DateTimeImmutable());
                if (!$entity->getCriadoPor()) $entity->setCriadoPor($this->getUser());
                // Preserva configuracoes de publicacao, permissoes e regras WME.
                if ($id === null) $entity->setConfiguracoes(['wme_api_enabled' => false, 'mostrar_para' => 'logado']);
                foreach ($data['campos'] as $i => $cd) {
                    $field = $existing[$cd['chave']] ?? new FormBuilderCampo();
                    $field->setChave($cd['chave'])->setLabel($cd['label'])->setTipo($cd['tipo'])->setOrdem($i * 10);
                    $field->setObrigatorio($cd['obrigatorio'])->setPlaceholder($cd['placeholder'] ?? null)->setAjuda($cd['ajuda'] ?? null);
                    $field->setValorPadrao($cd['valor_padrao'] ?? null)->setOpcoes($cd['opcoes'] ?? []);
                    $entity->addCampo($field);
                }
                $em->persist($entity);
                $em->flush();
                $this->addFlash('success', 'Formulario salvo. As configuracoes de publicacao foram preservadas.');
                return $this->redirectToRoute('admin4_form_studio_editar', ['id' => $entity->getId()]);
            } catch (\JsonException|\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
        $response = $this->render('admin4/form_builder/studio.html.twig', [
            'definition' => $definition, 'studio_id' => $id, 'studio_error' => $error,
        ]);
        if ($error !== null) $response->setStatusCode(422);
        return $response;
    }

    private function validate(mixed $data): void
    {
        if (!is_array($data)) throw new \InvalidArgumentException('Objeto JSON esperado.');
        foreach (['nome' => 120, 'slug' => 80, 'descricao' => 255] as $key => $max) {
            if (!isset($data[$key]) || !is_string($data[$key]) || mb_strlen($data[$key]) > $max) throw new \InvalidArgumentException('Valor invalido: '.$key);
        }
        if (!trim($data['nome']) || !preg_match('/^[a-z0-9_-]{1,80}$/D', $data['slug'])) throw new \InvalidArgumentException('Nome ou slug invalido.');
        if (!is_bool($data['ativo'] ?? null) || !is_array($data['campos'] ?? null) || !array_is_list($data['campos']) || count($data['campos']) > 100) throw new \InvalidArgumentException('Lista de campos invalida (maximo 100).');
        $keys = [];
        $types = ['text','textarea','number','email','url','date','datetime','time','select','select_multiple','radio','checkbox','toggle','file','image','hidden','html','divider','heading'];
        foreach ($data['campos'] as $c) {
            if (!is_array($c) || !is_string($c['chave'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $c['chave']) || in_array($c['chave'], ['__proto__','prototype','constructor'], true) || isset($keys[$c['chave']])) throw new \InvalidArgumentException('Chave invalida ou duplicada.');
            $keys[$c['chave']] = true;
            if (!in_array($c['tipo'] ?? null, $types, true) || !is_bool($c['obrigatorio'] ?? null)) throw new \InvalidArgumentException('Tipo ou obrigatoriedade invalidos.');
            foreach (['label' => 120, 'placeholder' => 255, 'ajuda' => 500, 'valor_padrao' => 500] as $key => $max) {
                $v = $c[$key] ?? null;
                if (($key === 'label' && (!is_string($v) || !trim($v))) || ($v !== null && (!is_string($v) || mb_strlen($v) > $max))) throw new \InvalidArgumentException('Propriedade invalida: '.$key);
            }
            if (isset($c['opcoes']) && !is_array($c['opcoes'])) throw new \InvalidArgumentException('Opcoes devem ser objeto JSON.');
        }
        $graph = [];
        foreach ($data['campos'] as $c) {
            $opts = $c['opcoes'] ?? [];
            $conditions = $opts['mostrarSe'] ?? [];
            if (!is_array($conditions)) throw new \InvalidArgumentException('mostrarSe invalido.');
            $deps = array_keys($conditions);
            if (isset($opts['condicional'])) {
                $condition = $opts['condicional'];
                if (!is_array($condition) || !is_string($condition['campo'] ?? null)) throw new \InvalidArgumentException('Condicional invalida.');
                $deps[] = $condition['campo'];
            }
            foreach ($deps as $dep) if (!isset($keys[$dep])) throw new \InvalidArgumentException('Dependencia inexistente: '.$dep);
            $graph[$c['chave']] = $deps;
        }
        $visit = function (string $key, array $stack = []) use (&$visit, $graph): void {
            if (isset($stack[$key])) throw new \InvalidArgumentException('Condicao circular: '.$key);
            $stack[$key] = true;
            foreach ($graph[$key] as $dep) $visit($dep, $stack);
        };
        foreach (array_keys($graph) as $key) $visit($key);
    }
}
