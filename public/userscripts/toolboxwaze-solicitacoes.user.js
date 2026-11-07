// ==UserScript==
// @name         ToolboxWaze — Solicitações via WME
// @namespace    https://github.com/andresdiniz/ToolboxWaze
// @version      1.3.0
// @description  Cria solicitações no ToolboxWaze diretamente do WME, pré-preenchendo contexto do objeto selecionado (incluindo lock level).
// @author       Andrés Diniz
// @match        https://www.waze.com/editor*
// @match        https://www.waze.com/*/editor*
// @match        https://beta.waze.com/editor*
// @grant        none
// ==/UserScript==

(function () {
  'use strict';

  const CONFIG = {
    apiUrl: 'https://seu-dominio.com/api/solicitacoes',
    apiToken: 'SEU_HASH_FIXO_AQUI',
    scriptVersion: '1.3.0'
  };

  const SCRIPT_ID = 'toolboxwaze-solicitacoes';
  const LOG_PREFIX = '[ToolboxWaze Solicitações]';

  let wmeSDK = null;
  let currentContext = {};
  let uiReady = false;

  function log(...args) { console.log(LOG_PREFIX, ...args); }
  function logWarn(...args) { console.warn(LOG_PREFIX, ...args); }
  function logError(...args) { console.error(LOG_PREFIX, ...args); }

  log(`Script carregado (v${CONFIG.scriptVersion}) em ${location.href}`);

  if (!window.SDK_INITIALIZED) {
    logError('SDK_INITIALIZED não disponível.');
    return;
  }

  window.SDK_INITIALIZED
    .then(() => { log('SDK_INITIALIZED resolvido.'); return initSdk(); })
    .then(() => { log('getWmeSdk OK. Módulos:', Object.keys(wmeSDK)); return waitForWme(); })
    .then(() => { log('WME pronto.'); start(); })
    .catch((err) => logError('Falha na inicialização:', err));

  function initSdk() {
    try {
      wmeSDK = getWmeSdk({ scriptId: SCRIPT_ID, scriptName: 'ToolboxWaze — Solicitações' });
      log('SDK obtido com sucesso.');
    } catch (err) { logError('Erro ao obter SDK:', err); throw err; }
  }

  function waitForWme() {
    return new Promise((resolve) => {
      if (wmeSDK.State.isReady()) { log('WME já estava pronto.'); resolve(); return; }
      log('Aguardando evento wme-ready…');
      wmeSDK.Events.once({ eventName: 'wme-ready' }).then(() => { log('Evento wme-ready recebido.'); resolve(); });
    });
  }

  function start() {
    injectStyles();
    createFloatingButton();
    registerSelectionListener();
    uiReady = true;
    log(`v${CONFIG.scriptVersion} iniciado com sucesso.`);
    setTimeout(checkButtonVisibility, 1500);
  }

  window.twDiagnostico = function () {
    const btn = document.getElementById('tw-fab');
    const overlay = document.getElementById('tw-overlay');
    const diag = {
      versao: CONFIG.scriptVersion,
      sdkObtido: !!wmeSDK,
      uiPronta: uiReady,
      botaoNoDom: !!btn,
      botaoVisivel: btn ? btn.offsetParent !== null : false,
      modalExiste: !!overlay,
      contextoAtual: currentContext,
      gmDisponiveis: {
        unsafeWindow: typeof unsafeWindow !== 'undefined',
        GM_xmlhttpRequest: typeof GM_xmlhttpRequest !== 'undefined',
        GM_getValue: typeof GM_getValue !== 'undefined'
      }
    };
    console.log(LOG_PREFIX, '=== DIAGNÓSTICO ===', diag);
    return diag;
  };

  function checkButtonVisibility() {
    const btn = document.getElementById('tw-fab');
    if (!btn) { logWarn('Botão 📋 não encontrado no DOM.'); return; }
    const rect = btn.getBoundingClientRect();
    const elAtPoint = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
    const isButton = elAtPoint === btn || btn.contains(elAtPoint);
    log('Visibilidade do botão 📋:', { rect: { top: rect.top, left: rect.left, width: rect.width, height: rect.height }, elementoPorCima: elAtPoint?.tagName, ehOBotao: isButton });
    if (!isButton) logWarn('Algo está cobrindo o botão 📋.');
  }

  function injectStyles() {
    log('Injetando estilos…');
    const style = document.createElement('style');
    style.textContent = `
      #tw-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); display: none; align-items: center; justify-content: center; z-index: 99999; }
      #tw-modal { background: #fff; border-radius: 12px; padding: 24px; width: 500px; max-width: 92vw; max-height: 85vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,.35); font-family: -apple-system, sans-serif; }
      #tw-modal h2 { margin: 0 0 16px; font-size: 18px; color: #1a73e8; }
      #tw-modal label { display: block; margin: 12px 0 4px; font-size: 13px; font-weight: 600; color: #333; }
      #tw-modal input, #tw-modal select, #tw-modal textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
      #tw-modal textarea { resize: vertical; min-height: 70px; }
      #tw-modal .tw-hint { font-size: 11px; color: #777; margin-top: 2px; }
      #tw-modal .tw-prefill { background: #f0f7ff; border-left: 4px solid #1a73e8; padding: 10px 14px; border-radius: 6px; font-size: 12px; margin-bottom: 14px; line-height: 1.6; }
      #tw-modal .tw-prefill strong { color: #1a73e8; }
      #tw-modal .tw-prefill .warn { color: #d93025; font-weight: 600; }
      #tw-actions { display: flex; gap: 10px; margin-top: 18px; }
      #tw-actions button { flex: 1; padding: 11px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; font-weight: 600; transition: background .2s; }
      #tw-send { background: #1a73e8; color: #fff; }
      #tw-send:hover { background: #1557b0; }
      #tw-send:disabled { background: #9ac0f5; cursor: not-allowed; }
      #tw-cancel { background: #eee; color: #333; }
      #tw-cancel:hover { background: #ddd; }
      #tw-fab { position: fixed; bottom: 60px; right: 24px; z-index: 99998; background: #1a73e8; color: #fff; border: none; border-radius: 50%; width: 56px; height: 56px; font-size: 24px; cursor: pointer; box-shadow: 0 4px 14px rgba(0,0,0,.35); transition: transform .15s; }
      #tw-fab:hover { transform: scale(1.08); background: #1557b0; }
      #tw-status { margin-top: 14px; font-size: 13px; min-height: 18px; }
      #tw-status.success { color: #188038; font-weight: 600; }
      #tw-status.error { color: #d93025; font-weight: 600; }
      #tw-status.loading { color: #1a73e8; }
    `;
    document.head.appendChild(style);
    log('Estilos injetados.');
  }

  function createFloatingButton() {
    const btn = document.createElement('button');
    btn.id = 'tw-fab';
    btn.textContent = '📋';
    btn.title = 'Criar solicitação no ToolboxWaze';
    btn.addEventListener('click', () => { log('Botão 📋 clicado.'); openModal(); });
    document.body.appendChild(btn);
    log('Botão flutuante 📋 criado (bottom: 60px, right: 24px).');
  }

  function registerSelectionListener() {
    log('Registrando listener de seleção…');
    wmeSDK.Events.on({
      eventName: 'wme-selection-changed',
      eventHandler: () => {
        const feature = wmeSDK.Editing.getSelectedFeatures()?.[0];
        if (!feature) { log('Seleção limpa.'); currentContext = {}; return; }
        currentContext = buildContext(feature);
        log('Seleção atual:', currentContext);
      }
    });
    log('Listener de seleção registrado.');
  }

  function buildContext(feature) {
    const ctx = { objectType: feature.modelName ?? feature.type, objectId: feature.id, url: location.href };
    log(`Construindo contexto para ${ctx.objectType} #${ctx.objectId}`);

    if (feature.modelName === 'segment') {
      try {
        const seg = wmeSDK.DataModel.Segments.getById({ segmentId: feature.id });
        if (seg) {
          ctx.lockLevel = seg.lockLevel ?? null;
          ctx.roadType = seg.roadType ?? null;
          ctx.streetName = seg.primaryStreetName ?? null;
          log(`Segmento carregado — lockLevel: ${ctx.lockLevel}, roadType: ${ctx.roadType}`);
        } else logWarn('Segmento não encontrado no DataModel.');
      } catch (err) { logError('Erro ao ler segmento:', err); }
    }

    if (feature.modelName === 'venue') {
      try {
        const venue = wmeSDK.DataModel.Venues.getById({ venueId: feature.id });
        if (venue) { ctx.name = venue.name ?? null; ctx.category = venue.category ?? null; log(`POI carregado — nome: ${ctx.name}`); }
      } catch (err) { logError('Erro ao ler POI:', err); }
    }

    try {
      ctx.mapCenter = wmeSDK.Map.getMapCenter();
      ctx.zoom = wmeSDK.Map.getZoomLevel();
    } catch (err) { logWarn('Não foi possível obter centro/zoom:', err); }

    return ctx;
  }

  function openModal() {
    log('Abrindo modal…');
    let overlay = document.getElementById('tw-overlay');
    if (!overlay) {
      log('Criando modal pela primeira vez…');
      overlay = document.createElement('div');
      overlay.id = 'tw-overlay';
      overlay.innerHTML = `
        <div id="tw-modal">
          <h2>📋 Nova solicitação — ToolboxWaze</h2>
          <div class="tw-prefill" id="tw-prefill">Carregando contexto…</div>
          <label>Tipo de solicitação</label>
          <select id="tw-tipo">
            <option value="nivel">Nível (Upgrade/Downgrade)</option>
            <option value="imagem_satelite">Imagem de Satélite</option>
            <option value="oops">Oops de Editor</option>
          </select>
          <div id="tw-nivel-fields">
            <label>Tipo de pedido</label>
            <select id="tw-tipo-nivel">
              <option value="upgrade">Upgrade</option>
              <option value="downgrade">Downgrade</option>
            </select>
            <label>Nível atual</label>
            <input type="number" id="tw-nivel-atual" min="1" max="6" placeholder="Ex: 3" />
            <div class="tw-hint">Pré-preenchido com o lock level do segmento, se disponível.</div>
            <label>Nível desejado</label>
            <input type="number" id="tw-nivel-desejado" min="1" max="6" placeholder="Ex: 4" />
            <label>Motivação / justificativa</label>
            <textarea id="tw-motivacao" placeholder="Descreva o motivo (mín. 20 caracteres)"></textarea>
          </div>
          <label>Descrição / comentários</label>
          <textarea id="tw-descricao" placeholder="Informações adicionais (opcional)"></textarea>
          <div id="tw-actions">
            <button id="tw-cancel">Cancelar</button>
            <button id="tw-send">Enviar solicitação</button>
          </div>
          <div id="tw-status"></div>
        </div>`;
      document.body.appendChild(overlay);
      document.getElementById('tw-cancel').addEventListener('click', () => { log('Modal cancelado.'); closeModal(); });
      document.getElementById('tw-send').addEventListener('click', submitRequest);
      log('Modal criado e listeners registrados.');
    }
    prefillModal();
    overlay.style.display = 'flex';
    log('Modal exibido.');
  }

  function closeModal() {
    const overlay = document.getElementById('tw-overlay');
    if (overlay) overlay.style.display = 'none';
    log('Modal fechado.');
  }

  function prefillModal() {
    const prefill = document.getElementById('tw-prefill');
    if (!currentContext.objectId) {
      prefill.innerHTML = '<span class="warn">⚠️ Selecione um segmento ou POI no mapa antes de enviar.</span>';
      logWarn('Nenhum objeto selecionado — prefill não aplicado.');
      return;
    }
    let html = `<strong>Objeto:</strong> ${currentContext.objectType} #${currentContext.objectId}<br>`;
    if (currentContext.lockLevel !== undefined && currentContext.lockLevel !== null) {
      html += `<strong>🔒 Bloqueio de nível (lock):</strong> ${currentContext.lockLevel}<br>`;
    } else {
      html += '<span class="warn">⚠️ Lock level não disponível para este objeto.</span><br>';
    }
    if (currentContext.roadType) html += `<strong>Tipo de via:</strong> ${currentContext.roadType}<br>`;
    if (currentContext.streetName) html += `<strong>Rua:</strong> ${currentContext.streetName}<br>`;
    if (currentContext.mapCenter) html += `<strong>Centro:</strong> ${currentContext.mapCenter.lat.toFixed(5)}, ${currentContext.mapCenter.lon.toFixed(5)}<br>`;
    html += `<strong>Zoom:</strong> ${currentContext.zoom}`;
    prefill.innerHTML = html;
    log('Prefill aplicado ao modal.');

    if (currentContext.lockLevel) {
      const nivelAtual = document.getElementById('tw-nivel-atual');
      if (nivelAtual && !nivelAtual.value) {
        nivelAtual.value = currentContext.lockLevel;
        log(`Nível atual pré-preenchido com lock level: ${currentContext.lockLevel}`);
      }
    }
  }

  async function submitRequest() {
    log('Enviando solicitação…');
    const status = document.getElementById('tw-status');
    const sendBtn = document.getElementById('tw-send');
    status.className = 'loading';
    status.textContent = 'Enviando…';
    sendBtn.disabled = true;

    const tipo = document.getElementById('tw-tipo').value;
    const tipoNivel = document.getElementById('tw-tipo-nivel')?.value;
    const nivelAtual = document.getElementById('tw-nivel-atual')?.value;
    const nivelDesejado = document.getElementById('tw-nivel-desejado')?.value;
    const motivacao = document.getElementById('tw-motivacao')?.value;
    const descricao = document.getElementById('tw-descricao').value;

    if (tipo === 'nivel' && (!nivelAtual || !nivelDesejado)) {
      status.className = 'error';
      status.textContent = 'Preencha os níveis atual e desejado.';
      sendBtn.disabled = false;
      logWarn('Validação falhou: níveis não preenchidos.');
      return;
    }

    const payload = {
      tipo,
      dados: {
        tipoNivel: tipoNivel ?? null,
        nivelAtual: nivelAtual ? parseInt(nivelAtual) : null,
        nivelDesejado: nivelDesejado ? parseInt(nivelDesejado) : null,
        motivacao: motivacao || null
      },
      descricao,
      contexto: {
        url: currentContext.url,
        objectType: currentContext.objectType,
        objectId: currentContext.objectId,
        lockLevel: currentContext.lockLevel,
        roadType: currentContext.roadType,
        streetName: currentContext.streetName,
        mapCenter: currentContext.mapCenter,
        zoom: currentContext.zoom
      }
    };

    log('Enviando payload:', payload);

    const startTime = performance.now();
    try {
      const res = await fetch(CONFIG.apiUrl, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-Api-Token': CONFIG.apiToken },
        body: JSON.stringify(payload)
      });
      const elapsed = (performance.now() - startTime).toFixed(0);
      log(`← HTTP ${res.status} em ${elapsed}ms`);
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || `HTTP ${res.status}`);
      }
      const result = await res.json();
      status.className = 'success';
      status.textContent = `✅ Solicitação #${result.id} criada com sucesso!`;
      log(`Solicitação #${result.id} criada com sucesso.`);
      setTimeout(closeModal, 2000);
    } catch (error) {
      logError('Falha no envio:', error);
      status.className = 'error';
      status.textContent = `❌ ${error.message}`;
    } finally {
      sendBtn.disabled = false;
    }
  }
})();
