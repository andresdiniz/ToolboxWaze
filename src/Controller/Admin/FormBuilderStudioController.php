<?php

declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\FormBuilder;
use App\Entity\FormBuilderCampo;
use App\Entity\FormBuilderResposta;
use App\Entity\Solicitacao;
use App\Repository\FormBuilderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin4/form-studio', name: 'admin4_form_studio_')]
#[IsGranted('ROLE_ADMIN')]
final class FormBuilderStudioController extends AbstractController
{
    private const VISIBILIDADES = ['todos', 'logado', 'visitante'];

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
        $cfgView = $this->configView($entity, $id === null);
        $published = $id !== null && $entity->isAtivo() && (($entity->getConfiguracoes() ?? [])['wme_api_enabled'] ?? false) === true;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('form_studio_'.($id ?? 'new'), $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF invalido.');
            }
            $postedCfg = $request->request->all()['cfg'] ?? null;
            if (is_array($postedCfg)) $cfgView = $this->configFromInput($postedCfg);
            try {
                if ($published && $request->request->get('confirm_published') !== '1') {
                    throw new \InvalidArgumentException('Este formulario esta publicado no script WME: marque a confirmacao para salvar alteracoes que valem imediatamente para quem usa o script.');
                }
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
                $unused = null;
                foreach ($existing as $key => $field) {
                    if (isset($incoming[$key])) continue;
                    // Remover/renomear campo so e seguro enquanto nada foi enviado por este formulario.
                    $unused ??= $id === null || ($em->getRepository(Solicitacao::class)->count(['formulario' => $entity]) === 0 && $em->getRepository(FormBuilderResposta::class)->count(['formulario' => $entity]) === 0);
                    if (!$unused) throw new \InvalidArgumentException('Remocao ou renomeacao de campos exige migracao explicita porque o formulario ja tem solicitacoes ou respostas: '.$key);
                    $entity->removeCampo($field);
                    $em->remove($field);
                    unset($existing[$key]);
                }
                $entity->setNome($data['nome'])->setSlug($data['slug'])->setDescricao($data['descricao'] ?: null)->setAtivo($data['ativo']);
                $entity->setAtualizadoEm(new \DateTimeImmutable());
                if (!$entity->getCriadoPor()) $entity->setCriadoPor($this->getUser());
                // Preserva configuracoes de publicacao, permissoes e regras WME.
                if ($id === null) $entity->setConfiguracoes(['wme_api_enabled' => false, 'mostrar_para' => 'logado']);
                if (is_array($postedCfg)) $this->applyConfig($entity, $cfgView);
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
            'cfg' => $cfgView, 'wme_published' => $published,
        ]);
        if ($error !== null) $response->setStatusCode(422);
        return $response;
    }

    /** @return array{success_message:string,redirect_url:string,email_notificacao:string,mostrar_para:string,limite_envios:string,expira_em:string} */
    private function configView(FormBuilder $entity, bool $isNew): array
    {
        $c = $entity->getConfiguracoes() ?? [];
        $visibility = $c['mostrar_para'] ?? ($isNew ? 'logado' : 'todos');

        return [
            'success_message' => is_string($c['success_message'] ?? null) ? $c['success_message'] : '',
            'redirect_url' => is_string($c['redirect_url'] ?? null) ? $c['redirect_url'] : '',
            'email_notificacao' => is_string($c['email_notificacao'] ?? null) ? $c['email_notificacao'] : '',
            'mostrar_para' => in_array($visibility, self::VISIBILIDADES, true) ? $visibility : 'todos',
            // Vazio = chave ausente = padrao do canal (a API WME limita a 1 envio por e-mail).
            'limite_envios' => isset($c['limite_envios']) && is_numeric($c['limite_envios']) ? (string) (int) $c['limite_envios'] : '',
            'expira_em' => is_string($c['expira_em'] ?? null) ? $c['expira_em'] : '',
        ];
    }

    /** @param array<mixed> $input @return array{success_message:string,redirect_url:string,email_notificacao:string,mostrar_para:string,limite_envios:string,expira_em:string} */
    private function configFromInput(array $input): array
    {
        $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';

        return [
            'success_message' => $text('success_message'),
            'redirect_url' => $text('redirect_url'),
            'email_notificacao' => $text('email_notificacao'),
            'mostrar_para' => $text('mostrar_para') ?: 'todos',
            'limite_envios' => $text('limite_envios'),
            'expira_em' => $text('expira_em'),
        ];
    }

    /**
     * Mescla as configuracoes editaveis sem tocar nas demais chaves (wme_api_enabled,
     * wme_roles, unificacao_campos_pendente...). Campo vazio remove a chave.
     *
     * @param array{success_message:string,redirect_url:string,email_notificacao:string,mostrar_para:string,limite_envios:string,expira_em:string} $v
     */
    private function applyConfig(FormBuilder $entity, array $v): void
    {
        if (mb_strlen($v['success_message']) > 255) throw new \InvalidArgumentException('Mensagem de sucesso: maximo 255 caracteres.');
        if (!in_array($v['mostrar_para'], self::VISIBILIDADES, true)) throw new \InvalidArgumentException('Visibilidade invalida.');
        if ($v['email_notificacao'] !== '' && (mb_strlen($v['email_notificacao']) > 180 || !filter_var($v['email_notificacao'], FILTER_VALIDATE_EMAIL))) throw new \InvalidArgumentException('E-mail de notificacao invalido.');
        if ($v['redirect_url'] !== '') {
            $url = $v['redirect_url'];
            $relative = str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\');
            $absolute = filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
            if (mb_strlen($url) > 500 || (!$relative && !$absolute)) throw new \InvalidArgumentException('URL de redirecionamento invalida (use http(s):// ou um caminho iniciando em /).');
        }
        $limit = null;
        if ($v['limite_envios'] !== '') {
            if (!ctype_digit($v['limite_envios']) || (int) $v['limite_envios'] > 1000000) throw new \InvalidArgumentException('Limite de envios deve ser um inteiro entre 0 e 1000000 (0 = ilimitado; vazio = padrao do canal).');
            $limit = (int) $v['limite_envios'];
        }
        if ($v['expira_em'] !== '') {
            try { new \DateTimeImmutable($v['expira_em']); } catch (\Exception) { throw new \InvalidArgumentException('Data de expiracao invalida (ex.: 2026-12-31 23:59).'); }
        }

        $config = $entity->getConfiguracoes() ?? [];
        $set = static function (array &$config, string $key, mixed $value): void {
            if ($value === null || $value === '') unset($config[$key]); else $config[$key] = $value;
        };
        $set($config, 'success_message', $v['success_message']);
        $set($config, 'redirect_url', $v['redirect_url']);
        $set($config, 'email_notificacao', $v['email_notificacao']);
        $set($config, 'limite_envios', $limit);
        $set($config, 'expira_em', $v['expira_em']);
        $config['mostrar_para'] = $v['mostrar_para'];
        $entity->setConfiguracoes($config);
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
