(() => {
  'use strict';

  // ───────────────────────────── Vocabulário ─────────────────────────────
  const ROLES = [
    ['ROLE_CHAMP', 'Champ'],
    ['ROLE_GLOBAL_CHAMP', 'Champ global'],
    ['ROLE_EDITOR', 'Editor'],
    ['ROLE_ADMIN', 'Administrador'],
  ];
  const roleName = role => (ROLES.find(([value]) => value === role) || [role, role])[1];

  // [tipo, nome, ícone, dica]
  const LIBRARY = [
    ['text', 'Texto curto', 'bi-input-cursor-text', 'Nome, título, apelido'],
    ['textarea', 'Texto longo', 'bi-text-paragraph', 'Descrição, justificativa'],
    ['number', 'Número', 'bi-123', 'Nível, quantidade'],
    ['select', 'Lista de opções', 'bi-menu-button-wide', 'Escolher uma opção numa lista'],
    ['radio', 'Escolha única', 'bi-ui-radios', 'Poucas opções, todas visíveis'],
    ['checkbox', 'Caixa de confirmação', 'bi-check2-square', '“Confirmo que…”'],
    ['url', 'Link (URL)', 'bi-link-45deg', 'Permalink do mapa, site'],
    ['email', 'E-mail', 'bi-envelope', ''],
    ['date', 'Data', 'bi-calendar-event', ''],
    ['file', 'Anexo (imagem)', 'bi-paperclip', 'Prints e fotos'],
  ];
  const TYPE_NAMES = {
    ...Object.fromEntries(LIBRARY.map(([type, name]) => [type, name])),
    datetime: 'Data e hora', time: 'Hora', select_multiple: 'Lista (várias respostas)', toggle: 'Interruptor',
    hidden: 'Campo oculto', html: 'Texto livre', divider: 'Divisor', heading: 'Título', image: 'Imagem',
  };
  const typeName = type => TYPE_NAMES[type] || type;

  const LENGTH_TYPES = ['text', 'textarea', 'email'];
  const PLACEHOLDER_TYPES = ['text', 'textarea', 'email', 'url', 'number'];
  const CONDITION_TYPES = ['select', 'radio', 'checkbox', 'text', 'textarea', 'number', 'email', 'url', 'date'];
  const KNOWN_KEYS = ['opcoes', 'mostrarSe', 'roles', 'permissoesOpcoes', 'variantes', 'min', 'max', 'minLength', 'maxLength',
    'inteiro', 'valorObrigatorio', 'preenchimento', 'zoomMinimo', 'validacao', 'maxArquivos', 'maxBytes', 'mimeTypes'];
  const AUTOFILL = [
    ['', 'Não preencher (a pessoa digita)'],
    ['wme.permalink', 'Link do mapa que está aberto no WME'],
    ['wme.segmentIds', 'Números dos segmentos selecionados'],
    ['wme.venueName', 'Nome do local selecionado'],
    ['wme.objectLockLevel', 'Nível de bloqueio do objeto selecionado'],
    ['account.wazeNickname', 'Apelido do editor no Waze'],
    ['wme.userState', 'Estado do editor no mapa'],
  ];
  const MIME_CHOICES = [['image/jpeg', 'JPG'], ['image/png', 'PNG'], ['image/webp', 'WEBP']];
  const VARIANT_PROPS = {
    label: 'Pergunta', placeholder: 'Texto de exemplo', ajuda: 'Texto de ajuda',
    min: 'Valor mínimo', max: 'Valor máximo', minLength: 'Mínimo de caracteres', maxLength: 'Máximo de caracteres',
  };
  const PREVIEW_PROFILES = [
    ['Usuário comum', []], ['Champ', ['ROLE_CHAMP']], ['Champ global', ['ROLE_GLOBAL_CHAMP']],
    ['Editor', ['ROLE_EDITOR']], ['Administrador', ['ROLE_ADMIN']],
  ];

  // ───────────────────────────── Utilitários ─────────────────────────────
  const isObj = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  const clone = value => JSON.parse(JSON.stringify(value));
  const slugify = (text, sep) => String(text ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, sep).split(sep).filter(Boolean).join(sep);
  const isEmpty = value => value === undefined || value === null || value === '' ||
    (Array.isArray(value) && !value.length) || (isObj(value) && !Object.keys(value).length);

  // Cria elementos: filhos primeiro, propriedades depois (assim `value` de <select> funciona).
  function h(tag, props = {}, ...children) {
    const node = document.createElement(tag);
    for (const child of children.flat(Infinity)) {
      if (child === null || child === undefined || child === false) continue;
      node.append(child.nodeType ? child : document.createTextNode(String(child)));
    }
    for (const [key, value] of Object.entries(props)) {
      if (value === undefined || value === null || value === false) continue;
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value;
      else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
      else if (key in node) node[key] = value;
      else node.setAttribute(key, value === true ? '' : value);
    }
    return node;
  }

  function init() {
    const host = document.querySelector('[data-form-studio]');
    if (!host || host.dataset.ready) return;
    host.dataset.ready = '1';

    const $ = id => host.querySelector('#' + id);
    const state = JSON.parse($('fbs-initial').textContent);
    const studioId = host.dataset.studioId || '';
    let selected = -1;
    let tab = 'campo';
    let dirty = false;
    let slugTouched = Boolean(studioId);

    for (const field of state.campos) {
      if (!isObj(field.opcoes) && !(Array.isArray(field.opcoes) && field.opcoes.length)) field.opcoes = {};
    }

    // O que já existe no servidor fica protegido (chave e valores das opções não mudam).
    const lockedKeys = new Set(studioId ? state.campos.map(field => field.chave) : []);
    const lockedValues = new Map(state.campos.map(field => [field.chave, new Set(studioId ? optionList(field).map(o => o.value) : [])]));
    const autoKey = new WeakSet();

    // ─────────────────────── Acesso às regras do campo ───────────────────────
    const legacy = field => Array.isArray(field.opcoes);
    const cfg = field => (isObj(field.opcoes) ? field.opcoes : {});

    function setRule(field, key, value) {
      if (!isObj(field.opcoes)) return;
      if (isEmpty(value)) delete field.opcoes[key];
      else field.opcoes[key] = value;
      touch();
    }

    function optionList(field) {
      const raw = isObj(field.opcoes) ? field.opcoes.opcoes : null;
      if (Array.isArray(raw)) {
        return raw.map(option => (option && typeof option === 'object'
          ? { value: String(option.value ?? ''), label: String(option.label ?? option.value ?? '') }
          : { value: String(option), label: String(option) }));
      }
      if (isObj(raw)) {
        return Object.entries(raw).map(([value, label]) => ({
          value: String(value), label: isObj(label) ? String(label.label ?? value) : String(label),
        }));
      }
      return [];
    }

    const writeOptions = (field, list) => { field.opcoes.opcoes = list.map(({ value, label }) => ({ value, label })); };
    const isList = field => ['select', 'radio'].includes(field.tipo);
    const fieldByKey = key => state.campos.find(field => field.chave === key);
    const labelOf = key => fieldByKey(key)?.label || key;
    const optionLabel = (key, value) => optionList(fieldByKey(key) || { opcoes: {} }).find(o => o.value === String(value))?.label ?? value;

    function valueText(key, value) {
      const dependency = fieldByKey(key);

      if (dependency?.tipo === 'checkbox') return value === true ? 'marcada' : 'desmarcada';

      return (Array.isArray(value) ? value : [value]).map(item => optionLabel(key, item)).join(' ou ');
    }

    const conditionText = conditions => Object.entries(conditions)
      .map(([key, value]) => `${labelOf(key)}: ${valueText(key, value)}`).join(' e ');

    // ───────────────────────────── Chaves e valores ─────────────────────────────
    function uniqueKey(base, self) {
      const root = base || 'campo';
      let key = root;
      let n = 2;

      while (state.campos.some(field => field !== self && field.chave === key)) key = `${root}_${n++}`;

      return key;
    }

    // Reescreve referências quando uma chave nova (ainda não salva) muda de nome.
    function renameKey(oldKey, newKey) {
      if (oldKey === newKey) return;

      const rename = object => Object.fromEntries(Object.entries(object).map(([key, value]) => [key === oldKey ? newKey : key, value]));

      for (const field of state.campos) {
        const rules = cfg(field);

        if (isObj(rules.mostrarSe) && oldKey in rules.mostrarSe) rules.mostrarSe = rename(rules.mostrarSe);

        for (const variant of Array.isArray(rules.variantes) ? rules.variantes : []) {
          if (isObj(variant.quando) && oldKey in variant.quando) variant.quando = rename(variant.quando);
        }

        if (isObj(rules.condicional) && rules.condicional.campo === oldKey) rules.condicional.campo = newKey;
      }
    }

    function renameOptionValue(field, oldValue, newValue) {
      if (oldValue === newValue) return;

      const rules = cfg(field);

      if (isObj(rules.permissoesOpcoes) && oldValue in rules.permissoesOpcoes) {
        rules.permissoesOpcoes = Object.fromEntries(Object.entries(rules.permissoesOpcoes).map(([key, roles]) => [key === oldValue ? newValue : key, roles]));
      }

      const swap = value => (Array.isArray(value) ? value.map(item => (item === oldValue ? newValue : item)) : value === oldValue ? newValue : value);

      for (const other of state.campos) {
        const otherRules = cfg(other);

        if (isObj(otherRules.mostrarSe) && field.chave in otherRules.mostrarSe) otherRules.mostrarSe[field.chave] = swap(otherRules.mostrarSe[field.chave]);

        for (const variant of Array.isArray(otherRules.variantes) ? otherRules.variantes : []) {
          if (isObj(variant.quando) && field.chave in variant.quando) variant.quando[field.chave] = swap(variant.quando[field.chave]);
        }
      }
    }

    // ───────────────────────────── Mensagens e estado ─────────────────────────────
    function message(items, kind = 'warning') {
      const box = $('fbs-message');
      const list = [].concat(items || []).filter(Boolean);

      box.replaceChildren();
      box.className = `alert alert-${kind}`;
      box.hidden = !list.length;

      if (list.length === 1) box.textContent = list[0];
      else if (list.length) box.append(h('strong', { text: 'Revise antes de salvar:' }), h('ul', { class: 'mb-0 mt-1' }, list.map(text => h('li', { text }))));
    }

    function touch() {
      dirty = true;
      renderList();
    }

    function collect() {
      return { ...state, nome: $('fbs-name').value.trim(), slug: $('fbs-slug').value.trim(), descricao: $('fbs-desc').value.trim(), ativo: $('fbs-active').checked };
    }

    // Digitar na janela de teste não conta como alteração do formulário.
    host.addEventListener('input', event => { if (!event.target.closest?.('dialog')) dirty = true; });

    // ─────────────────────── Nome → endereço automático ───────────────────────
    $('fbs-slug').addEventListener('input', () => { slugTouched = true; });
    $('fbs-name').addEventListener('input', () => {
      if (!slugTouched) $('fbs-slug').value = slugify($('fbs-name').value, '-').slice(0, 80);
    });

    // ───────────────────────────── Biblioteca ─────────────────────────────
    function defaultRules(type) {
      if (['select', 'radio'].includes(type)) return { opcoes: [{ value: 'opcao_1', label: 'Opção 1' }, { value: 'opcao_2', label: 'Opção 2' }] };
      if (type === 'file') return { maxArquivos: 3, maxBytes: 1048576, mimeTypes: ['image/jpeg', 'image/png', 'image/webp'] };

      return {};
    }

    for (const [type, name, icon, hint] of LIBRARY) {
      $('fbs-types').append(h('button', {
        type: 'button', class: 'fbs-tool', title: hint || name,
        onclick: () => {
          const field = {
            chave: uniqueKey(slugify(name, '_')), label: name, tipo: type, obrigatorio: false,
            placeholder: null, ajuda: null, valor_padrao: null, opcoes: defaultRules(type),
          };

          autoKey.add(field);
          lockedValues.set(field.chave, new Set());
          state.campos.push(field);
          selected = state.campos.length - 1;
          tab = 'campo';
          dirty = true;
          render();
          host.querySelector('#fbs-prop-label')?.focus();
          host.querySelector('#fbs-prop-label')?.select();
        },
      }, h('i', { class: `bi ${icon}`, 'aria-hidden': 'true' }), h('span', { text: name }), hint ? h('small', { text: hint }) : null));
    }

    // ───────────────────────────── Lista de campos ─────────────────────────────
    function chipsFor(field) {
      const rules = cfg(field);
      const chips = [];

      if (field.obrigatorio) chips.push(['Obrigatório', 'req']);
      if (isObj(rules.mostrarSe) && Object.keys(rules.mostrarSe).length) chips.push(['Aparece só se: ' + conditionText(rules.mostrarSe), 'rule']);
      if (Array.isArray(rules.roles) && rules.roles.length) chips.push(['Só ' + rules.roles.map(roleName).join(' ou '), 'lock']);
      if (isObj(rules.permissoesOpcoes) && Object.keys(rules.permissoesOpcoes).length) chips.push(['Opções restritas por papel', 'lock']);
      if (Array.isArray(rules.variantes) && rules.variantes.length) chips.push(['Muda conforme a resposta', 'rule']);
      if (isObj(rules.preenchimento) && rules.preenchimento.origem) chips.push(['Preenche sozinho', 'auto']);

      return chips;
    }

    function move(index, delta) {
      const target = index + delta;

      if (target < 0 || target >= state.campos.length) return;

      [state.campos[index], state.campos[target]] = [state.campos[target], state.campos[index]];
      selected = target;
      dirty = true;
      render();
    }

    function renderList() {
      const area = $('fbs-fields');

      area.replaceChildren();
      $('fbs-empty').hidden = state.campos.length > 0;
      $('fbs-count').textContent = `${state.campos.length} campo(s)` + (dirty ? ' · alterações ainda não salvas' : '');

      state.campos.forEach((field, index) => {
        const actions = [
          ['Subir', () => move(index, -1), 'bi-arrow-up'],
          ['Descer', () => move(index, 1), 'bi-arrow-down'],
          ['Duplicar', () => {
            const copy = clone(field);

            copy.chave = uniqueKey(`${field.chave}_copia`);
            autoKey.add(copy);
            lockedValues.set(copy.chave, new Set());
            state.campos.splice(index + 1, 0, copy);
            selected = index + 1;
            dirty = true;
            render();
          }, 'bi-files'],
          ['Remover', () => {
            if (!window.confirm('Remover este campo? Se o formulário já recebeu respostas, o sistema não vai permitir salvar a remoção.')) return;

            state.campos.splice(index, 1);
            selected = -1;
            dirty = true;
            render();
          }, 'bi-trash'],
        ];

        const card = h('article', {
          class: 'card fbs-field' + (selected === index ? ' selected' : ''), tabIndex: 0, role: 'button',
          'aria-label': `Editar campo ${field.label}`,
          onclick: () => { selected = index; renderList(); renderProps(); },
          onkeydown: event => { if (event.target === card && ['Enter', ' '].includes(event.key)) { event.preventDefault(); selected = index; renderList(); renderProps(); } },
        }, h('div', { class: 'card-body' },
          h('div', { class: 'fbs-field-title' }, h('strong', { text: field.label || '(sem pergunta)' }), h('span', { class: 'fbs-type', text: typeName(field.tipo) })),
          h('div', { class: 'fbs-chips' }, chipsFor(field).map(([text, kind]) => h('span', { class: `fbs-chip fbs-chip-${kind}`, text }))),
          h('div', { class: 'fbs-field-actions' }, actions.map(([text, run, icon]) => h('button', {
            type: 'button', class: 'btn btn-sm btn-outline-secondary', title: text, 'aria-label': text,
            onclick: event => { event.stopPropagation(); run(); },
          }, h('i', { class: `bi ${icon}`, 'aria-hidden': 'true' }), ' ', text))),
        ));

        area.append(card);
      });
    }

    // ───────────────────────────── Controles reutilizáveis ─────────────────────────────
    let uid = 0;
    const nextId = () => `fbs-ctl-${++uid}`;

    function control(labelText, input, help) {
      const id = input.id || (input.id = nextId());

      return h('div', { class: 'fbs-ctl' }, h('label', { class: 'form-label', htmlFor: id, text: labelText }), input, help ? h('small', { class: 'form-text', text: help }) : null);
    }

    function textInput(value, onInput, { max = 255, placeholder = '', id, type = 'text' } = {}) {
      return h('input', { id, type, class: 'form-control form-control-sm', maxLength: max, value: value ?? '', placeholder, oninput: event => onInput(event.target.value) });
    }

    function numberInput(value, onInput, { min, max, step = 'any', placeholder = '' } = {}) {
      return h('input', {
        type: 'number', class: 'form-control form-control-sm', min, max, step, placeholder, value: value ?? '',
        oninput: event => onInput(event.target.value === '' ? undefined : Number(event.target.value)),
      });
    }

    function checkbox(text, checked, onChange, help) {
      return h('label', { class: 'form-check fbs-check' },
        h('input', { type: 'checkbox', class: 'form-check-input', checked: Boolean(checked), onchange: event => onChange(event.target.checked) }),
        h('span', {}, text, help ? h('small', { class: 'form-text d-block', text: help }) : null));
    }

    function section(title, help, ...content) {
      return h('section', { class: 'fbs-section' }, h('h3', { text: title }), help ? h('p', { class: 'form-text', text: help }) : null, ...content);
    }

    // ───────────────────────────── Painel do campo ─────────────────────────────
    function renderProps() {
      const area = $('fbs-properties');

      area.replaceChildren();

      const field = state.campos[selected];

      if (!field) {
        area.append(h('p', { class: 'small text-muted', text: 'Clique em um campo da lista para editar a pergunta, as opções e as regras.' }));
        return;
      }

      const hasRules = ['mostrarSe', 'roles', 'permissoesOpcoes', 'variantes'].some(key => !isEmpty(cfg(field)[key]));
      const tabs = [['campo', 'Campo'], ['regras', 'Regras' + (hasRules ? ' •' : '')], ['avancado', 'Avançado']];

      area.append(h('div', { class: 'fbs-tabs', role: 'tablist' }, tabs.map(([id, text]) => h('button', {
        type: 'button', role: 'tab', class: 'fbs-tab' + (tab === id ? ' active' : ''), 'aria-selected': String(tab === id), text,
        onclick: () => { tab = id; renderProps(); },
      }))));

      if (legacy(field) && tab !== 'avancado') {
        area.append(h('div', { class: 'alert alert-warning small mt-3', text: 'Este campo usa um formato antigo de opções. Use a aba Avançado para editá-lo.' }));
        return;
      }

      area.append(h('div', { class: 'fbs-tabpanel' }, tab === 'campo' ? tabCampo(field) : tab === 'regras' ? tabRegras(field) : tabAvancado(field)));
    }

    // ───────────── Aba "Campo" ─────────────
    function tabCampo(field) {
      const rules = cfg(field);
      const parts = [];
      const isNew = !lockedKeys.has(field.chave);

      parts.push(control('Pergunta (o que a pessoa vai ler)', h('input', {
        id: 'fbs-prop-label', class: 'form-control form-control-sm', maxLength: 120, value: field.label ?? '',
        oninput: event => {
          field.label = event.target.value;

          if (autoKey.has(field)) {
            const old = field.chave;
            const next = uniqueKey(slugify(field.label, '_').slice(0, 60) || slugify(typeName(field.tipo), '_'), field);

            renameKey(old, next);
            lockedValues.set(next, lockedValues.get(old) || new Set());
            field.chave = next;
          }

          touch();
        },
      })));

      if (isNew) {
        parts.push(control('Tipo de campo', h('select', {
          class: 'form-select form-select-sm', value: field.tipo,
          onchange: event => {
            field.tipo = event.target.value;
            if (isList(field) && !optionList(field).length) field.opcoes.opcoes = defaultRules('select').opcoes;
            touch();
            renderProps();
          },
        }, LIBRARY.map(([type, name]) => h('option', { value: type, text: name })), LIBRARY.some(([type]) => type === field.tipo) ? null : h('option', { value: field.tipo, text: typeName(field.tipo) }))));
      } else {
        parts.push(h('p', { class: 'form-text' }, 'Tipo: ', h('strong', { text: typeName(field.tipo) }), ' (não pode ser alterado depois de salvo).'));
      }

      if (PLACEHOLDER_TYPES.includes(field.tipo)) {
        parts.push(control('Texto de exemplo (aparece apagado dentro do campo)', textInput(field.placeholder, value => { field.placeholder = value || null; dirty = true; })));
      }

      parts.push(control('Texto de ajuda (aparece abaixo do campo)', textInput(field.ajuda, value => { field.ajuda = value || null; dirty = true; }, { max: 500 })));
      parts.push(checkbox('Resposta obrigatória', field.obrigatorio, checked => { field.obrigatorio = checked; touch(); }));

      if (isList(field)) parts.push(optionsEditor(field));

      if (field.tipo === 'number') {
        parts.push(section('Limites do número', null,
          h('div', { class: 'fbs-two' },
            control('Mínimo', numberInput(rules.min, value => setRule(field, 'min', value))),
            control('Máximo', numberInput(rules.max, value => setRule(field, 'max', value)))),
          checkbox('Aceitar só números inteiros', rules.inteiro === true, checked => setRule(field, 'inteiro', checked || undefined))));
      }

      if (LENGTH_TYPES.includes(field.tipo)) {
        parts.push(section('Tamanho da resposta', null, h('div', { class: 'fbs-two' },
          control('Mínimo de caracteres', numberInput(rules.minLength, value => setRule(field, 'minLength', value), { min: 0, step: 1 })),
          control('Máximo de caracteres', numberInput(rules.maxLength, value => setRule(field, 'maxLength', value), { min: 0, step: 1 })))));
      }

      if (field.tipo === 'checkbox') {
        parts.push(checkbox('Precisa estar marcada para enviar', rules.valorObrigatorio === true, checked => setRule(field, 'valorObrigatorio', checked || undefined),
          'Use para “Confirmo que…”. Sem isso a pessoa pode deixar desmarcada.'));
      }

      if (field.tipo === 'url') {
        parts.push(checkbox('Deve ser um link do editor do Waze (permalink)', rules.validacao === 'permalink', checked => { setRule(field, 'validacao', checked ? 'permalink' : undefined); renderProps(); },
          'O script confere se o link é do Waze Map Editor.'));

        if (rules.validacao === 'permalink') {
          parts.push(control('Zoom mínimo do mapa no link', numberInput(rules.zoomMinimo, value => setRule(field, 'zoomMinimo', value), { min: 1, max: 22, step: 1, placeholder: '15' }),
            'Evita links de mapas muito afastados.'));
        }
      }

      if (field.tipo === 'file') parts.push(fileSettings(field));

      if (['text', 'textarea', 'url', 'number'].includes(field.tipo)) {
        const origin = isObj(rules.preenchimento) ? rules.preenchimento.origem || '' : '';
        const known = AUTOFILL.some(([value]) => value === origin);

        parts.push(section('Preenchimento automático (só no script do WME)', 'O script já deixa o campo preenchido; a pessoa ainda pode alterar.', h('select', {
          class: 'form-select form-select-sm', 'aria-label': 'Preencher automaticamente com', value: origin,
          onchange: event => {
            const value = event.target.value;
            const current = isObj(rules.preenchimento) ? rules.preenchimento : {};

            setRule(field, 'preenchimento', value ? { ...current, origem: value } : undefined);
          },
        }, AUTOFILL.map(([value, text]) => h('option', { value, text })), known ? null : h('option', { value: origin, text: `Origem personalizada (${origin})` }))));
      }

      return parts;
    }

    function fileSettings(field) {
      const rules = cfg(field);
      const accepted = Array.isArray(rules.mimeTypes) ? rules.mimeTypes : [];
      const megabytes = rules.maxBytes ? Math.round((rules.maxBytes / 1048576) * 100) / 100 : undefined;

      return section('Anexos', 'O script só aceita imagens.',
        h('div', { class: 'fbs-two' },
          control('Quantos arquivos (no máximo)', numberInput(rules.maxArquivos, value => setRule(field, 'maxArquivos', value), { min: 1, max: 10, step: 1 })),
          control('Tamanho máximo de cada um (MB)', numberInput(megabytes, value => setRule(field, 'maxBytes', value === undefined ? undefined : Math.round(value * 1048576)), { min: 0.1, max: 10, step: 0.1 }))),
        h('div', { class: 'fbs-ctl' }, h('span', { class: 'form-label d-block', text: 'Formatos aceitos' }),
          MIME_CHOICES.map(([mime, text]) => checkbox(text, accepted.includes(mime), checked => {
            const next = MIME_CHOICES.map(([value]) => value).filter(value => (value === mime ? checked : accepted.includes(value)));

            setRule(field, 'mimeTypes', next);
          }))));
    }

    // Opções de lista: texto, ordem, remoção e colagem em massa.
    function optionsEditor(field) {
      const list = optionList(field);
      const locked = lockedValues.get(field.chave) || new Set();
      const commit = () => { writeOptions(field, list); touch(); };
      const uniqueValue = (base, self) => {
        let value = base || 'opcao';
        let n = 2;

        while (list.some(option => option !== self && option.value === value)) value = `${base || 'opcao'}_${n++}`;

        return value;
      };

      const rows = list.map((option, index) => h('div', { class: 'fbs-option' },
        h('div', { class: 'fbs-option-row' },
          h('input', {
            class: 'form-control form-control-sm', maxLength: 120, value: option.label, 'aria-label': `Texto da opção ${index + 1}`,
            oninput: event => { option.label = event.target.value; commit(); },
            onchange: () => {
              if (locked.has(option.value)) return;

              const next = uniqueValue(slugify(option.label, '_').slice(0, 60), option);
              const old = option.value;

              option.value = next;
              renameOptionValue(field, old, next);
              commit();
              renderProps();
            },
          }),
          h('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', title: 'Subir', 'aria-label': `Subir opção ${index + 1}`, disabled: index === 0, onclick: () => { [list[index - 1], list[index]] = [list[index], list[index - 1]]; commit(); renderProps(); } }, '↑'),
          h('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', title: 'Descer', 'aria-label': `Descer opção ${index + 1}`, disabled: index === list.length - 1, onclick: () => { [list[index + 1], list[index]] = [list[index], list[index + 1]]; commit(); renderProps(); } }, '↓'),
          h('button', {
            type: 'button', class: 'btn btn-sm btn-outline-danger', title: 'Remover opção', 'aria-label': `Remover opção ${index + 1}`,
            onclick: () => {
              if (locked.has(option.value) && !window.confirm('Esta opção já existe no formulário salvo. Respostas antigas que usam essa opção continuam guardadas. Remover mesmo assim?')) return;

              list.splice(index, 1);

              if (isObj(field.opcoes.permissoesOpcoes)) {
                delete field.opcoes.permissoesOpcoes[option.value];
                if (isEmpty(field.opcoes.permissoesOpcoes)) delete field.opcoes.permissoesOpcoes;
              }

              commit();
              renderProps();
            },
          }, '🗑')),
        h('small', { class: 'fbs-option-value', text: `valor guardado: ${option.value}${locked.has(option.value) ? ' (fixo)' : ''}` })));

      const bulk = h('textarea', { class: 'form-control form-control-sm', rows: 3, placeholder: 'Uma opção por linha' });

      return section('Opções', 'O texto é o que a pessoa vê. O “valor guardado” é gerado sozinho.',
        h('div', { class: 'fbs-options' }, rows.length ? rows : h('p', { class: 'form-text text-danger', text: 'Adicione pelo menos uma opção.' })),
        h('button', {
          type: 'button', class: 'btn btn-sm btn-outline-primary',
          onclick: () => {
            const label = `Opção ${list.length + 1}`;

            list.push({ value: uniqueValue(slugify(label, '_')), label });
            commit();
            renderProps();
            [...host.querySelectorAll('.fbs-option input')].at(-1)?.select();
          },
        }, '+ Adicionar opção'),
        h('details', { class: 'fbs-bulk' }, h('summary', { text: 'Adicionar várias de uma vez' }), bulk,
          h('button', {
            type: 'button', class: 'btn btn-sm btn-outline-secondary mt-1',
            onclick: () => {
              for (const line of bulk.value.split('\n').map(text => text.trim()).filter(Boolean)) {
                if (list.some(option => option.label === line)) continue;
                list.push({ value: uniqueValue(slugify(line, '_').slice(0, 60)), label: line.slice(0, 120) });
              }

              commit();
              renderProps();
            },
          }, 'Adicionar')));
    }

    // ───────────── Aba "Regras" ─────────────
    // Construtor de condições: "[campo] for [valor]" (e mais condições, todas precisam valer).
    function conditionBuilder(field, { get, set, allowBool, minRows = 0, addText }) {
      const current = get();
      const entries = Object.entries(current);
      const candidates = state.campos.filter(other => other !== field && CONDITION_TYPES.includes(other.tipo) && (allowBool || other.tipo !== 'checkbox'));
      const wrap = h('div', { class: 'fbs-cond' });

      const save = next => { set(next); renderProps(); };
      const defaultValue = dependency => (dependency.tipo === 'checkbox' ? true : isList(dependency) ? optionList(dependency)[0]?.value ?? '' : '');

      entries.forEach(([key, value]) => {
        const dependency = fieldByKey(key);
        const free = candidates.filter(other => other.chave === key || !(other.chave in current));
        const row = h('div', { class: 'fbs-cond-row' });

        row.append(h('select', {
          class: 'form-select form-select-sm', 'aria-label': 'Campo da condição', value: key,
          onchange: event => {
            const next = {};

            for (const [k, v] of entries) {
              if (k === key) next[event.target.value] = defaultValue(fieldByKey(event.target.value));
              else next[k] = v;
            }

            save(next);
          },
        }, dependency ? null : h('option', { value: key, text: `⚠ campo removido (${key})` }), free.map(other => h('option', { value: other.chave, text: other.label || other.chave }))));

        row.append(valueControl(dependency, key, value, newValue => { current[key] = newValue; set(current); }));

        if (entries.length > minRows) {
          row.append(h('button', {
            type: 'button', class: 'btn btn-sm btn-outline-danger', title: 'Remover condição', 'aria-label': 'Remover condição',
            onclick: () => { const next = { ...current }; delete next[key]; save(next); },
          }, '🗑'));
        }

        wrap.append(row);
      });

      const remaining = candidates.filter(other => !(other.chave in current));

      if (remaining.length) {
        wrap.append(h('button', {
          type: 'button', class: 'btn btn-sm btn-outline-primary',
          onclick: () => save({ ...current, [remaining[0].chave]: defaultValue(remaining[0]) }),
        }, addText || '+ Adicionar condição'));
      } else if (!candidates.length) {
        wrap.append(h('p', { class: 'form-text', text: 'Adicione outros campos (lista, escolha única ou confirmação) para usar como condição.' }));
      }

      return wrap;
    }

    function valueControl(dependency, key, value, onValue) {
      if (!dependency) return h('span', { class: 'form-text text-danger', text: 'Escolha outro campo.' });

      if (dependency.tipo === 'checkbox') {
        return h('select', {
          class: 'form-select form-select-sm', 'aria-label': 'Valor', value: String(value === true),
          onchange: event => onValue(event.target.value === 'true'),
        }, h('option', { value: 'true', text: 'estiver marcada' }), h('option', { value: 'false', text: 'estiver desmarcada' }));
      }

      if (isList(dependency)) {
        const chosen = Array.isArray(value) ? value : [value];
        const options = optionList(dependency);

        return h('div', { class: 'fbs-values' }, options.map(option => checkbox(option.label, chosen.includes(option.value), checked => {
          const next = options.map(item => item.value).filter(item => (item === option.value ? checked : chosen.includes(item)));

          // Sem nenhuma marcada é um estado temporário: a verificação antes de salvar avisa.
          onValue(next.length === 1 ? next[0] : next);
          renderProps();
        })));
      }

      return h('input', { class: 'form-control form-control-sm', 'aria-label': 'Valor', placeholder: 'Texto igual a…', maxLength: 255, value: typeof value === 'string' ? value : '', oninput: event => onValue(event.target.value) });
    }

    function rolePicker(selectedRoles, onChange) {
      const unknown = selectedRoles.filter(role => !ROLES.some(([value]) => value === role));

      return h('div', { class: 'fbs-roles' }, [...ROLES, ...unknown.map(role => [role, role])].map(([role, text]) => checkbox(text, selectedRoles.includes(role), checked => {
        onChange([...ROLES.map(([value]) => value), ...unknown].filter(value => (value === role ? checked : selectedRoles.includes(value))));
      })));
    }

    function tabRegras(field) {
      const rules = cfg(field);
      const parts = [];

      // 1) Mostrar só se…
      const hasCondition = isObj(rules.mostrarSe) && Object.keys(rules.mostrarSe).length;
      const legacyCondition = isObj(rules.condicional);

      parts.push(section('Mostrar este campo só se…', 'Sem condição, o campo aparece sempre. Com várias condições, todas precisam valer.',
        legacyCondition ? h('p', { class: 'form-text text-warning', text: 'Este campo ainda tem uma condição no formato antigo (aba Avançado). Ela continua valendo.' }) : null,
        conditionBuilder(field, {
          allowBool: true, addText: hasCondition ? '+ Outra condição' : '+ Adicionar condição',
          get: () => (isObj(rules.mostrarSe) ? rules.mostrarSe : {}),
          set: next => setRule(field, 'mostrarSe', next),
        })));

      // 2) Quem pode ver
      const roles = Array.isArray(rules.roles) ? rules.roles : [];

      parts.push(section('Quem pode ver este campo', 'Só vale no script do WME. Quem não tiver o papel nem recebe o campo.',
        h('label', { class: 'form-check fbs-check' },
          h('input', { type: 'radio', name: 'fbs-roles-mode', class: 'form-check-input', checked: !roles.length, onchange: () => { setRule(field, 'roles', undefined); renderProps(); } }),
          h('span', { text: 'Todos' })),
        h('label', { class: 'form-check fbs-check' },
          h('input', { type: 'radio', name: 'fbs-roles-mode', class: 'form-check-input', checked: roles.length > 0, onchange: () => { setRule(field, 'roles', ['ROLE_CHAMP']); renderProps(); } }),
          h('span', { text: 'Somente quem tem um destes papéis:' })),
        roles.length ? rolePicker(roles, next => { setRule(field, 'roles', next); renderProps(); }) : null));

      // 3) Opções restritas
      if (isList(field)) {
        const permissions = isObj(rules.permissoesOpcoes) ? rules.permissoesOpcoes : {};
        const options = optionList(field);

        parts.push(section('Quem pode escolher cada opção', 'Opções restritas somem para quem não tem o papel (e o envio é recusado).',
          options.length ? options.map(option => {
            const needed = Array.isArray(permissions[option.value]) ? permissions[option.value] : [];

            return h('details', { class: 'fbs-perm' },
              h('summary', {}, h('span', { text: option.label }), h('small', { class: needed.length ? 'fbs-chip fbs-chip-lock' : 'text-muted', text: needed.length ? 'Só ' + needed.map(roleName).join(' ou ') : 'Todos' })),
              rolePicker(needed, next => {
                const map = { ...permissions };

                if (next.length) map[option.value] = next; else delete map[option.value];

                setRule(field, 'permissoesOpcoes', map);
                renderProps();
              }));
          }) : h('p', { class: 'form-text', text: 'Adicione opções primeiro.' })));
      }

      // 4) Variações
      parts.push(variantsSection(field));

      return parts;
    }

    function variantsSection(field) {
      const rules = cfg(field);
      const variants = Array.isArray(rules.variantes) ? rules.variantes : [];
      const allowed = field.tipo === 'number' ? ['label', 'placeholder', 'ajuda', 'min', 'max']
        : LENGTH_TYPES.includes(field.tipo) ? ['label', 'placeholder', 'ajuda', 'minLength', 'maxLength'] : ['label', 'ajuda'];
      const eligible = state.campos.filter(other => other !== field && ['select', 'radio', 'text', 'textarea', 'number', 'email', 'url', 'date'].includes(other.tipo));
      const commit = () => { setRule(field, 'variantes', variants); };

      const cards = variants.map((variant, index) => {
        const props = allowed.map(prop => {
          const active = Object.hasOwn(variant, prop);
          const numeric = ['min', 'max', 'minLength', 'maxLength'].includes(prop);
          const initial = { label: field.label, placeholder: field.placeholder, ajuda: field.ajuda }[prop] ?? '';

          return h('div', { class: 'fbs-variant-prop' },
            checkbox(`Trocar ${VARIANT_PROPS[prop].toLowerCase()}`, active, checked => {
              if (checked) variant[prop] = numeric ? (cfg(field)[prop] ?? 0) : initial || ''; else delete variant[prop];
              commit();
              renderProps();
            }),
            active ? (numeric
              ? numberInput(variant[prop], value => { if (value !== undefined) { variant[prop] = value; commit(); } }, { step: prop.endsWith('Length') ? 1 : 'any' })
              : textInput(variant[prop], value => { variant[prop] = value; commit(); }, { max: prop === 'ajuda' ? 500 : 255 })) : null);
        });

        return h('div', { class: 'fbs-variant' },
          h('div', { class: 'fbs-variant-head' }, h('strong', { text: `Variação ${index + 1}` }),
            h('button', { type: 'button', class: 'btn btn-sm btn-outline-danger', 'aria-label': `Remover variação ${index + 1}`, onclick: () => { variants.splice(index, 1); commit(); renderProps(); } }, 'Remover')),
          h('p', { class: 'form-text mb-1', text: 'Quando…' }),
          conditionBuilder(field, { allowBool: false, minRows: 1, addText: '+ Outra condição', get: () => (isObj(variant.quando) ? variant.quando : {}), set: next => { variant.quando = next; commit(); } }),
          h('p', { class: 'form-text mb-1 mt-2', text: '…então:' }), props);
      });

      return section('Mudar texto ou limites conforme outra resposta',
        'Exemplo: se “Tipo” for Downgrade, trocar a pergunta para “Nível atual do editor” e o máximo para 6.',
        cards,
        eligible.length
          ? h('button', {
            type: 'button', class: 'btn btn-sm btn-outline-primary',
            onclick: () => {
              const base = eligible[0];
              const value = isList(base) ? optionList(base)[0]?.value ?? '' : '';

              variants.push({ quando: { [base.chave]: value }, label: field.label });
              commit();
              renderProps();
            },
          }, '+ Adicionar variação')
          : h('p', { class: 'form-text', text: 'Adicione um campo de lista ou escolha única para usar como gatilho.' }));
    }

    // ───────────── Aba "Avançado" ─────────────
    function tabAvancado(field) {
      const rules = cfg(field);
      const parts = [];
      const isNew = !lockedKeys.has(field.chave);

      parts.push(control('Identificador do campo', h('input', {
        class: 'form-control form-control-sm font-monospace', maxLength: 80, value: field.chave, readOnly: !isNew,
        onchange: event => {
          const next = event.target.value.trim();

          if (!/^[A-Za-z0-9_-]{1,80}$/.test(next) || ['__proto__', 'prototype', 'constructor'].includes(next) || state.campos.some(other => other !== field && other.chave === next)) {
            event.target.value = field.chave;
            message('Identificador inválido ou já usado. Use letras sem acento, números, _ ou -.');
            return;
          }

          autoKey.delete(field);
          renameKey(field.chave, next);
          lockedValues.set(next, lockedValues.get(field.chave) || new Set());
          field.chave = next;
          touch();
        },
      }), isNew ? 'Gerado a partir da pergunta. Depois de salvo, não muda mais.' : 'Fixo: o formulário já foi salvo com este identificador.'));

      parts.push(control('Valor já preenchido (padrão)', textInput(field.valor_padrao, value => { field.valor_padrao = value || null; dirty = true; }, { max: 500 }), 'Usado pelo formulário do site.'));

      const unknown = Object.keys(rules).filter(key => !KNOWN_KEYS.includes(key));

      if (unknown.length) {
        parts.push(h('div', { class: 'alert alert-info small', text: `Este campo tem regras que o editor visual não mostra (${unknown.join(', ')}). Elas são mantidas ao salvar.` }));
      }

      if ('requerVisitaLink' in rules) parts.push(h('div', { class: 'alert alert-warning small', text: 'A regra “requerVisitaLink” impede publicar este formulário no script do WME.' }));

      const area = h('textarea', { id: 'fbs-prop-json', class: 'form-control font-monospace', rows: 12, spellcheck: false, value: JSON.stringify(field.opcoes ?? {}, null, 2) });
      const warn = h('small', { class: 'form-text', text: 'Só para quem sabe o que está fazendo. Alterações aqui substituem as regras do campo.' });

      area.addEventListener('input', () => {
        area.setCustomValidity('');

        try {
          const value = JSON.parse(area.value);

          if (!value || typeof value !== 'object') throw new Error('Use um objeto JSON.');

          field.opcoes = value;
          warn.className = 'form-text';
          warn.textContent = 'JSON válido: aplicado.';
          touch();
        } catch (error) {
          area.setCustomValidity('JSON inválido');
          warn.className = 'form-text text-danger';
          warn.textContent = 'JSON inválido: ' + error.message;
        }
      });

      parts.push(h('details', { class: 'fbs-json' }, h('summary', { text: 'Modo avançado (JSON)' }), area, warn));

      return parts;
    }

    // ───────────────────────────── Verificação antes de salvar ─────────────────────────────
    function problems(data) {
      const errors = [];
      const keys = new Set(data.campos.map(field => field.chave));

      if (!data.nome) errors.push('Dê um nome ao formulário.');
      if (!/^[a-z0-9_-]{1,80}$/.test(data.slug)) errors.push('O endereço do formulário deve ter só letras minúsculas sem acento, números, - ou _.');
      if (!data.campos.length) errors.push('Adicione pelo menos um campo.');

      const graph = {};

      for (const field of data.campos) {
        const where = `“${field.label || field.chave}”`;
        const rules = cfg(field);

        if (!String(field.label || '').trim()) errors.push(`Um campo está sem pergunta (${field.chave}).`);

        if (isList(field)) {
          const options = optionList(field);

          if (!options.length) errors.push(`${where}: adicione pelo menos uma opção.`);
          if (options.some(option => !option.label.trim() || !option.value)) errors.push(`${where}: há opção sem texto.`);
          if (new Set(options.map(option => option.value)).size !== options.length) errors.push(`${where}: há opções repetidas.`);
        }

        const refs = isObj(rules.mostrarSe) ? Object.keys(rules.mostrarSe) : [];

        graph[field.chave] = refs;

        for (const [key, value] of Object.entries(isObj(rules.mostrarSe) ? rules.mostrarSe : {})) {
          if (!keys.has(key)) errors.push(`${where}: a condição usa um campo que não existe mais.`);
          else if (value === '' || (Array.isArray(value) && !value.length)) errors.push(`${where}: a condição “${labelOf(key)}” está sem valor.`);
          else if (isList(fieldByKey(key))) {
            const valid = optionList(fieldByKey(key)).map(option => option.value);

            if ([].concat(value).some(item => !valid.includes(item))) errors.push(`${where}: a condição usa uma opção que não existe mais em “${labelOf(key)}”.`);
          }
        }

        for (const [index, variant] of (Array.isArray(rules.variantes) ? rules.variantes : []).entries()) {
          const changes = Object.keys(variant).filter(key => key !== 'quando');

          if (!changes.length) errors.push(`${where}: a variação ${index + 1} não muda nada.`);

          for (const [key, value] of Object.entries(isObj(variant.quando) ? variant.quando : {})) {
            if (!keys.has(key)) errors.push(`${where}: a variação ${index + 1} usa um campo que não existe.`);
            else if (value === '' || (Array.isArray(value) && !value.length)) errors.push(`${where}: a variação ${index + 1} está sem valor na condição.`);
          }
        }

        if (isObj(rules.permissoesOpcoes)) {
          const valid = optionList(field).map(option => option.value);

          for (const key of Object.keys(rules.permissoesOpcoes)) if (!valid.includes(key)) errors.push(`${where}: restrição de uma opção que não existe (${key}).`);
        }
      }

      const visit = (key, stack) => {
        if (stack.has(key)) return true;

        const next = new Set(stack).add(key);

        return (graph[key] || []).some(dep => visit(dep, next));
      };

      if (Object.keys(graph).some(key => visit(key, new Set()))) errors.push('Há condições que dependem umas das outras em círculo. Revise os campos “Mostrar só se…”.');

      return errors;
    }

    function payload() {
      const data = collect();
      const found = problems(data);

      if (found.length) throw found;
      if (!$('fbs-form').reportValidity()) throw ['Revise os campos destacados.'];

      return data;
    }

    $('fbs-form').onsubmit = event => {
      try {
        $('fbs-definition').value = JSON.stringify(payload());
        dirty = false;
      } catch (error) {
        event.preventDefault();
        message(Array.isArray(error) ? error : error.message);
        $('fbs-message').scrollIntoView?.({ block: 'center' });
      }
    };

    $('fbs-export').onclick = () => {
      try {
        const data = payload();
        const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
        const link = h('a', { href: url, download: `${data.slug}.json` });

        host.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      } catch (error) {
        message(Array.isArray(error) ? error : error.message);
      }
    };

    // ───────────────────────────── Testar formulário ─────────────────────────────
    // Mesmas regras do servidor: papéis, opções restritas, "mostrar só se" e variações.
    function viewFor(fields, roles) {
      const removed = new Set();
      let kept = [];

      for (const original of fields) {
        const field = { ...original, opcoes: clone(original.opcoes ?? {}) };
        const rules = cfg(field);

        if (Array.isArray(rules.roles) && rules.roles.length && !rules.roles.some(role => roles.includes(role))) { removed.add(field.chave); continue; }

        if (isList(field)) {
          const list = optionList(field).filter(option => {
            const needed = isObj(rules.permissoesOpcoes) ? rules.permissoesOpcoes[option.value] : null;

            return !Array.isArray(needed) || needed.some(role => roles.includes(role));
          });

          if (isObj(rules.permissoesOpcoes) && !list.length) { removed.add(field.chave); continue; }

          field.lista = list;
        }

        kept.push(field);
      }

      for (let changed = true; changed;) {
        changed = false;

        for (const field of kept) {
          if (Object.keys(cfg(field).mostrarSe || {}).some(key => removed.has(key))) {
            removed.add(field.chave);
            kept = kept.filter(item => item !== field);
            changed = true;
            break;
          }
        }
      }

      return kept;
    }

    function openPreview() {
      const data = collect();
      const area = $('fbs-preview-content');
      const answers = {};
      let roles = [];

      const matches = (key, expected) => (Array.isArray(expected) ? expected.includes(answers[key]) : answers[key] === expected);
      const visibleIn = (map, field, stack = new Set()) => {
        if (stack.has(field.chave)) return false;

        const next = new Set(stack).add(field.chave);

        return Object.entries(cfg(field).mostrarSe || {}).every(([key, value]) => map[key] && visibleIn(map, map[key], next) && matches(key, value));
      };

      const draw = () => {
        const fields = viewFor(data.campos, roles);
        const map = Object.fromEntries(fields.map(field => [field.chave, field]));

        area.replaceChildren(
          h('div', { class: 'fbs-ctl' }, h('label', { class: 'form-label', htmlFor: 'fbs-as', text: 'Ver como' }),
            h('select', {
              id: 'fbs-as', class: 'form-select form-select-sm', value: PREVIEW_PROFILES.findIndex(([, list]) => list.join() === roles.join()),
              onchange: event => { roles = PREVIEW_PROFILES[Number(event.target.value)][1]; draw(); },
            }, PREVIEW_PROFILES.map(([text], index) => h('option', { value: index, text })))),
          h('h3', { class: 'h5', text: data.nome || 'Formulário sem nome' }),
          data.descricao ? h('p', { text: data.descricao }) : null,
        );

        for (const field of fields) {
          if (!visibleIn(map, field)) continue;

          let view = field;

          for (const variant of Array.isArray(cfg(field).variantes) ? cfg(field).variantes : []) {
            if (Object.entries(variant.quando || {}).every(([key, expected]) => matches(key, expected))) view = { ...view, ...Object.fromEntries(Object.entries(variant).filter(([key]) => key !== 'quando')) };
          }

          const id = `fbs-pv-${field.chave}`;
          const hints = [];

          if (view.min !== undefined || view.max !== undefined) hints.push(`valor entre ${view.min ?? '…'} e ${view.max ?? '…'}`);
          if (view.minLength || view.maxLength) hints.push(`${view.minLength ?? 0} a ${view.maxLength ?? '…'} caracteres`);

          let input;

          if (isList(field)) {
            input = h('select', { id, class: 'form-select', value: answers[field.chave] ?? '', onchange: event => { answers[field.chave] = event.target.value || undefined; draw(); } },
              h('option', { value: '', text: 'Escolha…' }), field.lista.map(option => h('option', { value: option.value, text: option.label })));
          } else if (field.tipo === 'checkbox') {
            input = h('input', { id, type: 'checkbox', class: 'form-check-input d-block', checked: answers[field.chave] === true, onchange: event => { answers[field.chave] = event.target.checked; draw(); } });
          } else if (field.tipo === 'file') {
            input = h('input', { id, type: 'file', class: 'form-control', disabled: true });
          } else {
            input = h(field.tipo === 'textarea' ? 'textarea' : 'input', {
              id, class: 'form-control', placeholder: view.placeholder || '', value: answers[field.chave] ?? '',
              type: field.tipo === 'textarea' ? undefined : ['number', 'email', 'url', 'date'].includes(field.tipo) ? field.tipo : 'text',
              min: view.min, max: view.max,
              oninput: event => { answers[field.chave] = event.target.value; },
              onchange: () => draw(),
            });
          }

          area.append(h('div', { class: 'mb-3' },
            h('label', { class: 'form-label', htmlFor: id, text: (view.label || field.chave) + (field.obrigatorio ? ' *' : '') }), input,
            view.ajuda ? h('small', { class: 'form-text d-block', text: view.ajuda }) : null,
            hints.length ? h('small', { class: 'form-text d-block text-info', text: `(${hints.join(' · ')})` }) : null));
        }

        if (!fields.length) area.append(h('p', { class: 'text-muted', text: 'Nenhum campo disponível para este perfil.' }));
      };

      draw();
      $('fbs-dialog').showModal();
    }

    $('fbs-preview').onclick = openPreview;
    $('fbs-close').onclick = () => $('fbs-dialog').close();
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });

    function render() {
      renderList();
      renderProps();
    }

    render();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  document.addEventListener('turbo:load', init);
})();
