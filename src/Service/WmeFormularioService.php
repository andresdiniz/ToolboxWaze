<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\FormBuilder;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
final class WmeFormularioService
{
    public function __construct(private readonly EntityManagerInterface $em) {}
    public function available(User $user): array
    {
        $result = [];
        foreach ($this->em->getRepository(FormBuilder::class)->findBy(['ativo'=>true], ['nome'=>'ASC']) as $form) {
            try { $this->allowed($form, $user); $this->schema($form); $result[] = ['slug'=>$form->getSlug(), 'nome'=>$form->getNome()]; } catch (HttpException) {}
        }
        return $result;
    }
    public function get(string $slug, User $user): FormBuilder
    {
        $form = $this->em->getRepository(FormBuilder::class)->findOneBy(['slug'=>$slug, 'ativo'=>true]);
        if (!$form) throw new HttpException(404, 'Formulario nao encontrado.');
        $this->allowed($form, $user);
        return $form;
    }
    private function allowed(FormBuilder $form, User $user): void
    {
        $c = $form->getConfiguracoes() ?? [];
        if (($c['wme_api_enabled'] ?? false) !== true) throw new HttpException(403, 'Formulario nao habilitado para WME.');
        if (($c['mostrar_para'] ?? 'todos') === 'visitante') throw new HttpException(403, 'Formulario indisponivel.');
        if (!empty($c['expira_em']) && new \DateTimeImmutable($c['expira_em']) < new \DateTimeImmutable()) throw new HttpException(403, 'Formulario expirado.');
        $required = $c['wme_roles'] ?? [];
        if (!is_array($required)) throw new HttpException(503, 'Configuracao de permissoes invalida.');
        if ($required && !array_intersect($required, $user->getRoles())) throw new HttpException(403, 'Conta sem permissao para este formulario.');
    }
    public function schema(FormBuilder $form): array
    {
        $fields = []; $keys = [];
        foreach ($form->getCampos() as $f) {
            $key = $f->getChave(); $type = $f->getTipo(); $c = $f->getOpcoes() ?? [];
            if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $key) || in_array($key, ['__proto__','prototype','constructor'], true) || isset($keys[$key])) throw new HttpException(503, 'Chave de campo invalida ou duplicada.');
            if (!in_array($type, ['text','textarea','number','email','url','date','select','radio','checkbox','file','image'], true)) throw new HttpException(503, 'Tipo de campo ainda nao suportado no WME: '.$type);
            $keys[$key] = true;
            $field = ['chave'=>$key, 'tipo'=>$type === 'image' ? 'file' : $type, 'label'=>$f->getLabel(), 'obrigatorio'=>$f->isObrigatorio(), 'placeholder'=>$f->getPlaceholder(), 'ajuda'=>$f->getAjuda()];
            foreach (['min','max','minLength','maxLength','inteiro','valorObrigatorio','preenchimento','zoomMinimo','maxArquivos','maxBytes','mimeTypes','mostrarSe'] as $prop) if (array_key_exists($prop, $c)) $field[$prop] = $c[$prop];
            if (isset($c['condicional'])) {
                $condition = $c['condicional'];
                if (($condition['operador'] ?? '=') !== '=' || empty($condition['campo'])) throw new HttpException(503, 'Operador condicional nao suportado no WME.');
                $field['mostrarSe'] = [$condition['campo']=>$condition['valor'] ?? ''];
            }
            if (isset($c['validacao'])) {
                if (!in_array($c['validacao'], ['permalink','wkt'], true)) throw new HttpException(503, 'Validacao customizada exige adaptador WME.');
                $field['validacao'] = $c['validacao'];
            }
            if (in_array($type, ['select','radio'], true)) {
                $field['opcoes'] = [];
                foreach (($c['opcoes'] ?? []) as $value=>$label) {
                    if (is_array($label)) $field['opcoes'][] = ['value'=>(string)($label['value'] ?? ''), 'label'=>(string)($label['label'] ?? '')];
                    elseif (is_scalar($label)) $field['opcoes'][] = ['value'=>is_int($value) ? (string)$label : (string)$value, 'label'=>(string)$label];
                }
            }
            $fields[] = $field;
        }
        $map = array_column($fields, null, 'chave');
        foreach ($fields as $f) $this->visible($f['chave'], $map, []);
        $schema = ['slug'=>$form->getSlug(), 'nome'=>$form->getNome(), 'descricao'=>$form->getDescricao(), 'campos'=>$fields];
        $schema['versao'] = hash('sha256', json_encode([$schema, $form->getConfiguracoes()], JSON_THROW_ON_ERROR));
        return $schema;
    }
    private function visible(string $key, array $map, array $answers, array $stack = []): bool
    {
        if (isset($stack[$key]) || !isset($map[$key])) throw new HttpException(503, 'Condicao circular ou campo inexistente.');
        $stack[$key] = true; $visible = true;
        foreach (($map[$key]['mostrarSe'] ?? []) as $dep=>$expected) {
            $parent = $this->visible((string)$dep, $map, $answers, $stack);
            $match = is_array($expected) ? in_array($answers[$dep] ?? null, $expected, true) : ($answers[$dep] ?? null) === $expected;
            $visible = $visible && $parent && $match;
        }
        return $visible;
    }
    public function validate(array $schema, array $answers, array $uploads): array
    {
        $map = array_column($schema['campos'], null, 'chave'); $clean = []; $accepted = []; $errors = []; $total = 0;
        foreach ($answers as $key=>$value) if (!isset($map[$key])) $errors[$key][] = 'Campo desconhecido.';
        foreach ($uploads as $key=>$value) if (!isset($map[$key]) || $map[$key]['tipo'] !== 'file') $errors[$key][] = 'Anexo desconhecido.';
        foreach ($map as $key=>$f) {
            if (!$this->visible($key, $map, $answers)) continue;
            $v = $answers[$key] ?? null; $type = $f['tipo'];
            if ($type === 'file') {
                $list = $uploads[$key] ?? [];
                if (!is_array($list)) $list = [$list];
                if ($f['obrigatorio'] && !$list) $errors[$key][] = 'Anexo obrigatorio.';
                if (count($list) > min(5, max(1, (int)($f['maxArquivos'] ?? 1)))) $errors[$key][] = 'Quantidade de anexos excedida.';
                foreach ($list as $file) {
                    if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()) { $errors[$key][] = 'Upload invalido.'; continue; }
                    $size = $file->getSize(); $total += $size;
                    if ($size > min(1048576, max(1, (int)($f['maxBytes'] ?? 1048576)))) $errors[$key][] = 'Arquivo excede o limite.';
                    $mime = $file->getMimeType();
                    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true) || !in_array($mime, $f['mimeTypes'] ?? ['image/jpeg','image/png','image/webp'], true) || @getimagesize($file->getPathname()) === false) $errors[$key][] = 'Imagem invalida ou formato nao permitido.';
                }
                $accepted[$key] = $list; continue;
            }
            if ($type === 'checkbox') {
                if ($v !== null && !is_bool($v)) $errors[$key][] = 'Use booleano.';
                if ($f['obrigatorio'] && $v !== true) $errors[$key][] = 'Confirmacao obrigatoria.';
                if (array_key_exists('valorObrigatorio', $f) && $v !== $f['valorObrigatorio']) $errors[$key][] = 'Confirmacao invalida.';
                $clean[$key] = $v === true; continue;
            }
            if ($v !== null && !is_scalar($v)) { $errors[$key][] = 'Valor invalido.'; continue; }
            $text = trim((string)$v);
            if ($text === '') { if ($f['obrigatorio']) $errors[$key][] = 'Campo obrigatorio.'; continue; }
            if (mb_strlen($text) > min(20000, (int)($f['maxLength'] ?? 20000)) || mb_strlen($text) < (int)($f['minLength'] ?? 0)) $errors[$key][] = 'Comprimento fora dos limites.';
            if (array_key_exists('valorObrigatorio', $f) && $v !== $f['valorObrigatorio']) $errors[$key][] = 'Resposta exigida nao confirmada.';
            if (in_array($type, ['select','radio'], true) && !in_array($text, array_column($f['opcoes'] ?? [], 'value'), true)) $errors[$key][] = 'Opcao invalida.';
            if ($type === 'number' && (!is_numeric($text) || !is_finite((float)$text) || (isset($f['min']) && (float)$text < $f['min']) || (isset($f['max']) && (float)$text > $f['max']) || (($f['inteiro'] ?? false) && floor((float)$text) !== (float)$text))) $errors[$key][] = 'Numero invalido.';
            if ($type === 'email' && !filter_var($text, FILTER_VALIDATE_EMAIL)) $errors[$key][] = 'E-mail invalido.';
            if ($type === 'date') { $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text); if (!$date || $date->format('Y-m-d') !== $text) $errors[$key][] = 'Data invalida.'; }
            if ($type === 'url' && (!filter_var($text, FILTER_VALIDATE_URL) || !in_array(parse_url($text, PHP_URL_SCHEME), ['http','https'], true))) $errors[$key][] = 'URL invalida.';
            if (($f['validacao'] ?? '') === 'wkt' && !preg_match('/^(POLYGON|LINESTRING)\s*\(/i', $text)) $errors[$key][] = 'Use POLYGON ou LINESTRING.';
            if (($f['validacao'] ?? '') === 'permalink') {
                $u = parse_url($text); parse_str($u['query'] ?? '', $q);
                if (!$u || !in_array($u['scheme'] ?? '', ['https','http'], true) || !in_array($u['host'] ?? '', ['waze.com','www.waze.com','beta.waze.com'], true) || !preg_match('#^/(?:[a-zA-Z_-]+/)?editor/?$#', $u['path'] ?? '') || !is_scalar($q['zoomLevel'] ?? $q['zoom'] ?? 0) || (int)($q['zoomLevel'] ?? $q['zoom'] ?? 0) < (int)($f['zoomMinimo'] ?? 15)) $errors[$key][] = 'Permalink WME ou zoom invalido.';
            }
            $clean[$key] = $type === 'number' ? (float)$text : $text;
        }
        if ($total > 5242880) $errors['_anexos'][] = 'Limite total: 5 MB.';
        return [$clean, $accepted, $errors];
    }
}
