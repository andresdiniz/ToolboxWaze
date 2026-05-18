/**
 * Validação client-side de campos permalink WME (zoom ≥15).
 * Ativado em qualquer input com data-permalink="1".
 *
 * Regras espelhadas do SolicitacaoType::PERMALINK_PATTERN (PHP):
 *   - Domínio waze.com/editor/
 *   - Parâmetro zoom presente na URL
 *   - Valor do zoom ≥15
 */

const WAZE_EDITOR_RE = /^https?:\/\/(?:www\.|beta\.)?waze\.com\/editor\/[^"\s]*/i;
const ZOOM_RE        = /[?&]zoom=(\d+)/i;

/**
 * @param {string} value
 * @returns {{ ok: boolean, message?: string }}
 */
export function validatePermalink(value) {
  const v = value.trim();

  if (!v) return { ok: true };  // campo vazio é tratado pelo NotBlank do Symfony

  if (!WAZE_EDITOR_RE.test(v)) {
    return {
      ok: false,
      message: 'A URL deve ser do editor Waze (waze.com/editor).',
    };
  }

  const zoomMatch = ZOOM_RE.exec(v);
  if (!zoomMatch) {
    return {
      ok: false,
      message: 'A URL não contém o parâmetro zoom. Certifique-se de copiar a URL completa.',
    };
  }

  const zoom = parseInt(zoomMatch[1], 10);
  if (zoom < 15) {
    return {
      ok: false,
      message: `Zoom ${zoom} é insuficiente. O zoom mínimo exigido é 15.`,
    };
  }

  return { ok: true };
}

/**
 * Aplica feedback visual ao wrapper Bootstrap do campo.
 * @param {HTMLInputElement} input
 * @param {{ ok: boolean, message?: string }} result
 */
function applyFeedback(input, result) {
  const wrapper = input.closest('.mb-3') ?? input.parentElement;
  let   fb      = wrapper.querySelector('.permalink-feedback');

  input.classList.toggle('is-invalid', !result.ok);
  input.classList.toggle('is-valid',    result.ok && input.value.trim().length > 0);

  if (!result.ok) {
    if (!fb) {
      fb = document.createElement('div');
      fb.className = 'invalid-feedback permalink-feedback';
      input.insertAdjacentElement('afterend', fb);
    }
    fb.textContent = result.message ?? 'Permalink inválido.';
  } else if (fb) {
    fb.remove();
  }
}

/**
 * Inicializa todos os inputs [data-permalink] no documento.
 * Chame após o DOM estar pronto e também após injetar campos dinâmicos via AJAX.
 */
export function initPermalinkValidation() {
  document.querySelectorAll('[data-permalink]').forEach(input => {
    if (input.dataset.permalinkInit) return;  // evita duplo binding
    input.dataset.permalinkInit = '1';

    // Valida ao sair do campo
    input.addEventListener('blur', () => {
      applyFeedback(input, validatePermalink(input.value));
    });

    // Remove erro enquanto usuário digita (só reavaliar no blur)
    input.addEventListener('input', () => {
      if (input.classList.contains('is-invalid')) {
        const r = validatePermalink(input.value);
        if (r.ok) applyFeedback(input, r);
      }
    });
  });
}

/**
 * Bloqueia submit se algum permalink inválido ainda existir.
 * @param {HTMLFormElement} form
 * @returns {boolean} true = pode submeter
 */
export function validatePermalinksOnSubmit(form) {
  let allOk = true;
  form.querySelectorAll('[data-permalink]').forEach(input => {
    if (!input.value.trim()) return;  // vazio: Symfony trata
    const result = validatePermalink(input.value);
    applyFeedback(input, result);
    if (!result.ok) {
      allOk = false;
      input.focus();
    }
  });
  return allOk;
}
