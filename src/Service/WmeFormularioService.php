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
            try { $this->allowed($form, $user); $this->schema($form, $user); $result[] = ['slug'=>$form->getSlug(), 'nome'=>$form->getNome()]; } catch (HttpException) {}
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
    /**
     * Definicao do formulario para o WME. Com $user, aplica as regras de seguranca do formulario:
     *  - roles: campo so existe para quem tem um dos papeis;
     *  - permissoesOpcoes: opcao so existe para quem tem um dos papeis;
     *  - variantes: troca rotulo/ajuda/limites conforme outra resposta (o validate() tambem aplica).
     * Como o validate() trabalha sobre este schema, o que o usuario nao pode usar vira
     * "Campo desconhecido" / "Opcao invalida" no envio, e nao so some da tela.
     * Sem $user devolve a definicao completa (uso administrativo: conferir a publicacao).
     */
    public function schema(FormBuilder $form, ?User $user = null): array
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
            $field['_roles'] = $this->roleList($c['roles'] ?? null, $key.': regra roles invalida.');
            if (isset($c['permissoesOpcoes'])) {
                if (!in_array($type, ['select','radio'], true) || !is_array($c['permissoesOpcoes']) || $c['permissoesOpcoes'] === []) throw new HttpException(503, $key.': permissoesOpcoes exige select/radio com mapa opcao => papeis.');
                $values = array_column($field['opcoes'], 'value'); $perm = [];
                foreach ($c['permissoesOpcoes'] as $value=>$roles) {
                    if (!in_array((string)$value, $values, true)) throw new HttpException(503, $key.': permissoesOpcoes cita opcao inexistente: '.$value);
                    $perm[(string)$value] = $this->roleList($roles, $key.': papeis invalidos em permissoesOpcoes.');
                    if ($perm[(string)$value] === []) throw new HttpException(503, $key.': permissoesOpcoes sem papeis para a opcao '.$value.'.');
                }
                $field['_permissoes'] = $perm;
            }
            if (isset($c['variantes'])) $field['variantes'] = $this->variants($c['variantes'], $key);
            $fields[] = $field;
        }
        foreach ($fields as $f) foreach (($f['variantes'] ?? []) as $variant) foreach (array_keys($variant['quando']) as $dep) {
            if (!isset($keys[$dep])) throw new HttpException(503, $f['chave'].': variantes referencia campo inexistente: '.$dep);
        }
        if ($user !== null) $fields = $this->filterForUser($fields, $user);
        $fields = array_map(static function (array $f): array { unset($f['_roles'], $f['_permissoes']); return $f; }, $fields);
        $map = array_column($fields, null, 'chave');
        foreach ($fields as $f) $this->visible($f['chave'], $map, []);
        $schema = ['slug'=>$form->getSlug(), 'nome'=>$form->getNome(), 'descricao'=>$form->getDescricao(), 'campos'=>$fields];
        $schema['versao'] = hash('sha256', json_encode([$schema, $form->getConfiguracoes()], JSON_THROW_ON_ERROR));
        return $schema;
    }
    /** @return list<string> */
    private function roleList(mixed $value, string $message): array
    {
        if ($value === null) return [];
        if (!is_array($value) || !array_is_list($value)) throw new HttpException(503, $message);
        foreach ($value as $role) if (!is_string($role) || !preg_match('/^ROLE_[A-Z0-9_]{1,80}$/D', $role)) throw new HttpException(503, $message);
        return array_values(array_unique($value));
    }
    /** @return list<array<string,mixed>> */
    private function variants(mixed $raw, string $key): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > 10) throw new HttpException(503, $key.': variantes invalidas.');
        $out = [];
        foreach ($raw as $variant) {
            if (!is_array($variant) || !is_array($variant['quando'] ?? null) || $variant['quando'] === []) throw new HttpException(503, $key.': variante sem "quando".');
            $when = [];
            foreach ($variant['quando'] as $dep=>$expected) {
                $valid = is_string($expected) || (is_array($expected) && array_is_list($expected) && $expected !== [] && array_filter($expected, static fn (mixed $x): bool => !is_string($x)) === []);
                if (!is_string($dep) || $dep === '' || !$valid) throw new HttpException(503, $key.': "quando" invalido (use texto ou lista de textos).');
                $when[$dep] = $expected;
            }
            $item = ['quando'=>$when];
            foreach ($variant as $prop=>$value) {
                if ($prop === 'quando') continue;
                if (in_array($prop, ['label','placeholder','ajuda'], true) && is_string($value) && mb_strlen($value) <= 500) $item[$prop] = $value;
                elseif (in_array($prop, ['min','max'], true) && (is_int($value) || is_float($value))) $item[$prop] = $value;
                elseif (in_array($prop, ['minLength','maxLength'], true) && is_int($value) && $value >= 0) $item[$prop] = $value;
                else throw new HttpException(503, $key.': propriedade de variante nao suportada ou invalida: '.$prop);
            }
            $out[] = $item;
        }
        return $out;
    }
    /**
     * Aplica roles/permissoesOpcoes. Campo que depende (mostrarSe) de campo removido sai junto;
     * variante que depende de campo removido deixa de existir.
     * @param list<array<string,mixed>> $fields
     * @return list<array<string,mixed>>
     */
    private function filterForUser(array $fields, User $user): array
    {
        $roles = $user->getRoles(); $kept = []; $removed = [];
        foreach ($fields as $f) {
            if ($f['_roles'] !== [] && !array_intersect($f['_roles'], $roles)) { $removed[$f['chave']] = true; continue; }
            foreach (($f['_permissoes'] ?? []) as $value=>$required) {
                if (array_intersect($required, $roles)) continue;
                $f['opcoes'] = array_values(array_filter($f['opcoes'], static fn (array $o): bool => $o['value'] !== (string)$value));
            }
            if (isset($f['_permissoes']) && $f['opcoes'] === []) {
                if ($f['obrigatorio']) throw new HttpException(403, 'Conta sem opcoes disponiveis em: '.$f['chave']);
                $removed[$f['chave']] = true; continue;
            }
            $kept[] = $f;
        }
        do {
            $changed = false;
            foreach ($kept as $i=>$f) {
                foreach (array_keys($f['mostrarSe'] ?? []) as $dep) {
                    if (isset($removed[$dep])) { $removed[$f['chave']] = true; unset($kept[$i]); $changed = true; continue 2; }
                }
            }
        } while ($changed);
        $kept = array_values($kept);
        foreach ($kept as $i=>$f) {
            if (!isset($f['variantes'])) continue;
            $kept[$i]['variantes'] = array_values(array_filter($f['variantes'], static fn (array $v): bool => array_intersect_key($v['quando'], $removed) === []));
            if ($kept[$i]['variantes'] === []) unset($kept[$i]['variantes']);
        }
        return $kept;
    }
    private function answerMatches(mixed $actual, mixed $expected): bool
    {
        return is_array($expected) ? in_array($actual, $expected, true) : $actual === $expected;
    }
    /** Sobrepoe os limites da variante cujo "quando" casa com as respostas (o resto e so apresentacao). */
    private function withVariants(array $f, array $answers): array
    {
        foreach (($f['variantes'] ?? []) as $variant) {
            foreach ($variant['quando'] as $dep=>$expected) if (!$this->answerMatches($answers[$dep] ?? null, $expected)) continue 2;
            foreach (['min','max','minLength','maxLength'] as $prop) if (array_key_exists($prop, $variant)) $f[$prop] = $variant[$prop];
        }
        return $f;
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
            $f = $this->withVariants($f, $answers);
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
