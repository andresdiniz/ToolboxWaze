// ==UserScript==
// @name         Formulário de solicitações BR
// @namespace    https://github.com/andresdiniz/ToolboxWaze
// @version      4.2.5.18
// @description  Solicitações ao ToolboxWaze direto do WME: conexão por e-mail, contexto SDK, formulários da API, anexos, rascunhos, histórico com links, verificação de duplicatas, acompanhamento e notificações de alterações.
// @author       Andres Diniz
// @homepageURL  https://github.com/andresdiniz/ToolboxWaze
// @supportURL   https://github.com/andresdiniz/ToolboxWaze/issues
// @updateURL    https://raw.githubusercontent.com/andresdiniz/ToolboxWaze/main/ToolboxWaze.user.js
// @downloadURL  https://raw.githubusercontent.com/andresdiniz/ToolboxWaze/main/ToolboxWaze.user.js
// @match        https://www.waze.com/editor*
// @match        https://www.waze.com/*/editor*
// @match        https://beta.waze.com/editor*
// @match        https://beta.waze.com/*/editor*
// @exclude      https://www.waze.com/user/editor*
// @grant        unsafeWindow
// @grant        GM_getValue
// @grant        GM_setValue
// @grant        GM_deleteValue
// @grant        GM_addValueChangeListener
// @grant        GM_xmlhttpRequest
// @require      https://update.greasyfork.org/scripts/602363/1986340/WME%20Alert%20System.js
// @connect      wazetoolbox.acheireviews.com.br
// @connect      www.google-analytics.com
// @run-at       document-idle
// ==/UserScript==

/* global unsafeWindow, GM_getValue, GM_setValue, GM_deleteValue, GM_addValueChangeListener, GM_xmlhttpRequest, WmeAlertSystem */
(async function () {
  'use strict';

  if (window.top !== window.self) return;

  const VERSION = '4.2.5.18';
  const BASE = 'https://wazetoolbox.acheireviews.com.br';
  const API = BASE + '/api/wme';
  const PREFIX = '[Comunidade BR]';

  // Telemetria opcional. Preencha estas constantes para ativar o GA4.
  // Sem elas, apenas o monitoramento próprio permanece disponível.
  const GA_MEASUREMENT_ID = 'G-M30YGWFGV8';
  const GA_API_SECRET = 'liFqyf8RQ3yNiV_BeFpH0A';
  const MONITORING_ENDPOINT = API + '/monitoring/events';
  const TELEMETRY_FLUSH_MS = 30000;
  const TELEMETRY_MAX_QUEUE = 50;
  const PAGE = typeof unsafeWindow === 'undefined' ? window : unsafeWindow;

  const log = (...args) => console.info(PREFIX, ...args);
  const warn = (...args) => console.warn(PREFIX, ...args);

  const store = {
    get: async (key, fallback = null) => await GM_getValue(key, fallback),
    set: async (key, value) => await GM_setValue(key, value),
    del: async (key) => await GM_deleteValue(key)
  };

  const telemetryAllowed = () => analyticsEnabled && (gaClientId || MONITORING_ENDPOINT);

  function normalizeTelemetryParams(params) {
    const safe = {};

    for (const [key, value] of Object.entries(params || {})) {
      if (value == null) continue;

      const text = String(value).slice(0, 180);

      if (/email|token|nickname|password|permalink|latitude|longitude|\blat\b|\blon\b/i.test(key)) continue;

      safe[key] = text;
    }

    return safe;
  }

  function trackEvent(name, params = {}) {
    if (!telemetryAllowed() || !name) return;

    telemetryQueue.push({
      name: String(name).slice(0, 60),
      params: normalizeTelemetryParams(params),
      occurredAt: new Date().toISOString()
    });

    if (telemetryQueue.length >= 10) {
      flushTelemetry();
      return;
    }

    scheduleTelemetryFlush();
  }

  function trackTiming(name, durationMs, params = {}) {
    trackEvent('timing', {
      name,
      duration_ms: Math.max(0, Math.round(Number(durationMs) || 0)),
      ...params
    });
  }

  function scheduleTelemetryFlush() {
    if (telemetryTimer) return;

    telemetryTimer = setTimeout(() => {
      telemetryTimer = null;
      flushTelemetry();
    }, TELEMETRY_FLUSH_MS);
  }

  async function flushTelemetry() {
    clearTimeout(telemetryTimer);
    telemetryTimer = null;

    if (!telemetryQueue.length) return;

    const events = telemetryQueue.splice(0, TELEMETRY_MAX_QUEUE);

    if (analyticsEnabled && gaClientId && GA_MEASUREMENT_ID && GA_API_SECRET) {
      const payload = {
        client_id: gaClientId,
        events: events.map(item => ({
          name: item.name,
          params: {
            engagement_time_msec: 100,
            script_version: VERSION,
            ...item.params
          }
        }))
      };

      try {
        GM_xmlhttpRequest({
          method: 'POST',
          url: 'https://www.google-analytics.com/mp/collect?measurement_id=' +
            encodeURIComponent(GA_MEASUREMENT_ID) +
            '&api_secret=' + encodeURIComponent(GA_API_SECRET),
          headers: { 'Content-Type': 'application/json' },
          data: JSON.stringify(payload),
          anonymous: true,
          timeout: 10000
        });
      } catch (error) {
        warn('GA4:', error.message);
      }
    }

    if (MONITORING_ENDPOINT && token) {
      try {
        GM_xmlhttpRequest({
          method: 'POST',
          url: MONITORING_ENDPOINT,
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-API-TOKEN': token
          },
          data: JSON.stringify({
            scriptVersion: VERSION,
            events
          }),
          anonymous: true,
          timeout: 10000
        });
      } catch (error) {
        warn('Monitoramento próprio:', error.message);
      }
    }
  }

  async function setAnalyticsEnabled(enabled) {
    analyticsEnabled = true;
    await store.set('tw_analytics_enabled', analyticsEnabled);

    if (analyticsEnabled && !gaClientId) {
      gaClientId = crypto.randomUUID();
      await store.set('tw_ga_client_id', gaClientId);
    }

    if (analyticsEnabled) {
      trackEvent('analytics_enabled');
      scheduleTelemetryFlush();
    } else {
      telemetryQueue = [];
      clearTimeout(telemetryTimer);
      telemetryTimer = null;
    }

    updateAnalyticsButton();
  }

  const alerts = (await WmeAlertSystem.ready()).register({
    scriptId: 'toolboxwaze-solicitacoes',
    scriptName: 'Formulário de solicitações BR',
    version: VERSION,
    changelog: {
      mode: 'bell',
      loader: async () => [{
        version: '4.2.5.18',
        date: '2026-11-16',
        changes: {
          'pt-BR': '<p>Notificações no sino do WME sobre alterações nas suas solicitações (novo comentário ou mudança de status) enquanto você estiver conectado.</p>'
        }
      }]
    },
    links: {
      greasyFork: 'https://greasyfork.org/pt-BR/scripts/602363-wme-alert-system'
    }
  });

  alerts.notification.onAction('abrir-solicitacao', (notification) => {
    const url = notification?.data?.url;
    if (url) window.open(url, '_blank', 'noopener');
  });

  const uid = () => crypto.randomUUID();
  const TOKEN_KEY = 'tw_device_token';
  const LEGACY_KEY = 'toolbox_api_token';

  let sdk = null;
  let token = (await store.get(TOKEN_KEY, '')) || (await store.get(LEGACY_KEY, ''));
  let identity = null;
  let forms = [];
  let definition = null;
  let conn = 'carregando';
  let pendingInfo = null;
  let pollTimer = null;
  let modalOpen = false;
  let analyticsEnabled = true;
  let gaClientId = await store.get('tw_ga_client_id', null);
  if (!gaClientId) {
    gaClientId = crypto.randomUUID();
    await store.set('tw_ga_client_id', gaClientId);
  }
  let telemetryQueue = [];
  let telemetryTimer = null;

  const randomToken = () => {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
  };

  let answers = {};
  let files = {};
  let context = null;
  let dirty = false;
  let busy = false;
  let unresolved = null;
  let previousFocus = null;
  let loadSerial = 0;
  let restoring = false;
  let saveTimer = null;

  const restoredPending = await store.get('tw3_pending', null);
  unresolved = restoredPending;

  const host = document.createElement('div');
  host.id = 'toolbox-wme-v3';

  if (document.getElementById(host.id)) return;

  document.body.append(host);
  const root = host.attachShadow({ mode: 'open' });

  const el = (tag, text, parent, className) => {
    const node = document.createElement(tag);
    if (text != null) node.textContent = text;
    if (className) node.className = className;
    if (parent) parent.append(node);
    return node;
  };

  const css = el('style', null, root);

  css.textContent = `
  :host{all:initial;font:14px/1.5 system-ui,sans-serif;color:#16342b;position:relative;z-index:2147483646}
  *{box-sizing:border-box}
  [hidden]{display:none!important}
  button,input,select,textarea{font:inherit}
  button,a{touch-action:manipulation}
  button{cursor:pointer;border:1px solid #cbded5;border-radius:9px;background:#fff;color:#163d2b;padding:9px 13px;font-weight:600}
  button:hover{background:#edf7f1}
  button:disabled{opacity:.5;cursor:not-allowed}
  a{color:#147348}
  button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,a:focus-visible{outline:3px solid #71bca0;outline-offset:2px}
  .fab{position:fixed;bottom:76px;right:96px;background:#176d4c;color:white;border:0;border-radius:28px;box-shadow:0 8px 28px #0004;padding:14px 20px}
  .overlay{position:fixed;inset:0;background:#0b241dcc;display:grid;place-items:center;padding:20px;backdrop-filter:blur(3px)}
  .dialog{width:min(1050px,97vw);height:min(850px,94vh);display:flex;flex-direction:column;background:#f4f8f5;border-radius:18px;box-shadow:0 24px 90px #0006;overflow:hidden}
  header,footer{background:#fff;padding:16px 22px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  header{border-bottom:1px solid #dce9e1}
  footer{border-top:1px solid #dce9e1}
  h2{font-size:19px;margin:0}
  h3{font-size:14px;margin:0 0 10px}
  p{margin:4px 0 12px}
  .muted,small{color:#5c7468;font-size:12px}
  .spacer{flex:1}
  .scroll{overflow:auto;flex:1;padding:20px}
  .layout{display:grid;grid-template-columns:270px 1fr;gap:18px;align-items:start}
  .card{padding:16px;border:1px solid #dce9e1;border-radius:12px;background:#fff;margin-bottom:14px}
  .green{background:#eef8f2}
  .types{display:block}
  .types select{width:100%;padding:10px;border:1px solid #cbded5;border-radius:8px;background:#fff;color:#183c2b;font-weight:600}
  .types select:focus-visible{outline:3px solid #71bca0;outline-offset:2px}
  label{display:block;font-weight:600;margin-bottom:6px}
  input:not([type=checkbox]):not([type=radio]),select,textarea{width:100%;border:1px solid #cbded5;border-radius:8px;padding:10px;background:white;color:#183c2b}
  textarea{min-height:95px;resize:vertical}
  .field{margin-bottom:18px}
  .choices{display:flex;flex-wrap:wrap;gap:8px}
  .choice{display:flex;align-items:center;gap:7px;border:1px solid #dce9e1;border-radius:9px;padding:8px;font-size:13px;font-weight:400;margin:0}
  .choice:has(input:checked){border-color:#198754;background:#edf8f1}
  .primary{background:#198754;color:white;border-color:#198754}
  .primary:hover{background:#126a40}
  .status{white-space:pre-wrap;padding:10px 14px;border-radius:9px;margin-bottom:12px;background:#e8f2ed}
  .status.error{background:#fff0ef;color:#a32121}
  .status.success{background:#ddf4e7;color:#125d39}
  .error-text{color:#b3261e;font-size:12px;margin-top:5px}
  .bad{border-color:#b3261e!important}
  pre{white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.6 system-ui;margin:0;max-height:260px;overflow:auto}
  .row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .preview{display:flex;gap:8px;flex-wrap:wrap}
  .preview figure{margin:8px 0;border:1px solid #dce9e1;border-radius:8px;padding:6px;width:130px}
  .preview img{width:116px;height:80px;object-fit:cover}
  .preview small{display:block;overflow-wrap:anywhere}
  .preview button{padding:3px 7px;font-size:11px}
  @media(max-width:760px){
    .overlay{padding:5px}
    .dialog{height:98vh;width:99vw;border-radius:10px}
    .layout{grid-template-columns:1fr}
    .scroll{padding:12px}
    .types{grid-template-columns:1fr 1fr}
    header,footer{padding:12px}
    .side pre{max-height:110px}
    .fab{right:80px}
  }
  `;

  css.textContent += `
  .mine-request{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px;margin-bottom:8px;border:1px solid #dce9e1;border-radius:10px;text-decoration:none;color:#163d2b;background:#fff}
  .mine-request:hover{background:#eef8f2;border-color:#198754}
  .mine-request:focus-visible{outline:3px solid #71bca0;outline-offset:2px}
  .mine-open{margin-left:auto;font-size:12px;font-weight:600}
  `;

  css.textContent += `
  .steps ol{list-style:none;margin:0 0 12px;padding:0;display:grid;gap:8px}
  .steps li{display:grid;grid-template-columns:30px 1fr;gap:10px;align-items:start;padding:10px;border:1px solid #dce9e1;border-radius:10px;background:#f8fbf9}
  .steps .num{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:#dce9e1;color:#40594d;font-weight:700;font-size:13px}
  .steps li.current{border-color:#198754;background:#eef8f2;box-shadow:0 0 0 2px #19875422}
  .steps li.current .num,.steps li.done .num{background:#198754;color:#fff}
  .steps li.done{opacity:.75}
  .steps strong{display:block}
  .steps li span.muted{display:block}
  .why{background:#fff8e6;border:1px solid #f0dca0;border-radius:10px;padding:10px 12px;font-size:13px;color:#5b4a14}
  .why strong{color:#4a3b0d}
  .why p{margin:4px 0 0}
  .hint{font-size:12px;margin-top:5px;color:#5c7468}
  .hint.ok{color:#125d39}
  .hint.bad{color:#b3261e}
  `;
  css.textContent += `
  .fab{display:flex;align-items:center;gap:9px}
  .fab-dot{width:9px;height:9px;border-radius:50%;background:#ffd54d;box-shadow:0 0 0 2px #ffffff55;flex:none}
  .fab-dot.ok{background:#4ade80}
  .fab-dot.pending{background:#ffd54d}
  .fab-dot.error{background:#ff6b6b}
  .status.info::before{content:"ℹ️ "}
  .status.success::before{content:"✅ "}
  .status.error::before{content:"⚠️ "}
  .spinner{display:inline-block;width:14px;height:14px;border:2px solid #ffffff66;border-top-color:#fff;border-radius:50%;animation:tw-spin .8s linear infinite;vertical-align:-2px;margin-right:7px}
  @keyframes tw-spin{to{transform:rotate(360deg)}}
  .context-list{white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.6 system-ui;margin:0;max-height:260px;overflow:auto}
  .mine-status{display:inline-flex;align-items:center;gap:6px}
  .mine-status::before{content:"";width:8px;height:8px;border-radius:50%;background:#6b7280;flex:none}
  .mine-status.open{color:#1d4ed8}.mine-status.open::before{background:#3b82f6}
  .mine-status.pending{color:#92400e}.mine-status.pending::before{background:#f59e0b}
  .mine-status.done{color:#166534}.mine-status.done::before{background:#22c55e}
  .mine-status.rejected{color:#991b1b}.mine-status.rejected::before{background:#ef4444}
  .confirm-overlay{position:fixed;inset:0;background:#0b241dcc;display:grid;place-items:center;padding:20px;z-index:2147483647}
  .confirm-box{max-width:420px;width:100%;background:#fff;border-radius:14px;padding:18px;box-shadow:0 20px 60px #0006}
  .confirm-box h3{margin:0 0 8px}
  .confirm-box p{margin:0 0 16px}
  .why summary{cursor:pointer;font-weight:700;color:#4a3b0d}
  .why[open] summary{margin-bottom:8px}
  `;
  css.textContent += `
  .tabbar{display:flex;gap:6px;padding:12px 20px 0;background:#f4f8f5;flex-wrap:wrap}
  .tab{border:1px solid #cbded5;background:#fff;color:#163d2b;border-radius:999px;padding:7px 14px;font-size:13px;font-weight:600}
  .tab.active{background:#176d4c;border-color:#176d4c;color:#fff}
  .close-x{width:36px;height:36px;padding:0;font-size:18px;line-height:1}
  .detail-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
  .back{padding:6px 10px;font-size:13px}
  .table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #dce9e1;border-radius:10px;overflow:hidden}
  .table th,.table td{padding:10px;border-bottom:1px solid #eef3f0;text-align:left;vertical-align:middle}
  .table th{background:#f1f7f4;font-size:12px;color:#40594d;text-transform:uppercase;letter-spacing:.03em}
  .table tr:last-child td{border-bottom:0}
  .table .actions{display:flex;gap:6px;flex-wrap:wrap}
  .table .actions button,.table .actions a{padding:5px 9px;font-size:12px}
  .footer-close{margin-left:8px}
  @media(max-width:760px){
    .table{display:block;overflow-x:auto}
    .tabbar{padding:10px 12px 0}
  }
  `;

  css.textContent += `
  .card{padding:20px;margin-bottom:18px}
  .field{margin-bottom:24px;padding-bottom:4px}
  .field>label{margin-bottom:8px}
  .field>button{display:inline-flex;align-items:center;min-height:40px;margin-top:12px;margin-bottom:6px}
  .field>.hint,.field>.muted{margin-top:8px;margin-bottom:6px;line-height:1.6}
  .field>.error-text{margin-top:8px}
  .field input,.field select,.field textarea{padding:11px 12px}
  .preview{gap:12px;margin-top:8px}
  .preview button{margin-top:8px}
  footer{gap:12px}
  .types{margin-top:10px}
  @media(max-width:760px){.card{padding:15px}.field{margin-bottom:20px}}
  `;


  css.textContent += `
  .tw-requests,.tw-detail{padding:0;overflow:hidden;border:1px solid #d9e3e8;border-radius:14px;background:#fff;box-shadow:0 4px 16px #183c2b08}
  .tw-requests>div{overflow-x:auto}
  .tw-requests>div>p{margin:0;padding:24px;color:#465d55}
  .tw-requests .table{display:table;width:100%;border:0;border-radius:0;border-collapse:collapse;font-size:13px;color:#203a31}
  .tw-requests caption{text-align:left;padding:18px 20px;font-size:16px;font-weight:700;color:#163d2b;background:#fff;border-bottom:1px solid #e5ece8;caption-side:top}
  .tw-requests .table th{padding:12px 18px;background:#f3f7f5;color:#4b6358;font-size:11px;letter-spacing:.05em;white-space:nowrap}
  .tw-requests .table td{padding:16px 18px;border-bottom:1px solid #eaf0ed;vertical-align:middle;line-height:1.55}
  .tw-requests .table tbody tr:nth-child(even){background:#fbfdfc}
  .tw-requests .table tbody tr:hover,.tw-requests .table tbody tr:focus-within{background:#f0f8f3}
  .tw-requests .table td:first-child{font-weight:750;color:#176d4c;font-variant-numeric:tabular-nums;white-space:nowrap}
  .tw-requests .table td:nth-child(2){font-weight:600;min-width:170px;overflow-wrap:anywhere}
  .tw-requests .table td:nth-child(4){white-space:nowrap;color:#53685e;font-variant-numeric:tabular-nums}
  .tw-requests .table td.actions{display:table-cell;white-space:nowrap}
  .tw-requests .actions button,.tw-requests .actions a{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:6px 11px;border:1px solid #c8d9ce;border-radius:8px;font:600 12px/1.4 system-ui,sans-serif;text-decoration:none;vertical-align:middle;margin:2px 5px 2px 0;background:#fff;color:#175538}
  .tw-requests .actions button{background:#edf7f0;border-color:#bcd8c6}
  .tw-requests .actions button:hover,.tw-requests .actions a:hover{background:#dff0e5;border-color:#6da880}
  .tw-requests .mine-status{display:inline-flex;align-items:center;gap:7px;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:700;line-height:1.4;background:#edf0f2;color:#3e4e58;max-width:200px;white-space:normal}
  .tw-requests .mine-status.open{background:#eaf2ff;color:#234e91}
  .tw-requests .mine-status.pending{background:#fff3d4;color:#795000}
  .tw-requests .mine-status.done{background:#e3f5e9;color:#1b6039}
  .tw-requests .mine-status.rejected{background:#fde9e8;color:#962f2b}
  .tw-requests .mine-status::before{width:6px;height:6px;flex:none}
  .tw-detail .detail-head{margin:0;padding:18px 22px;gap:14px;flex-wrap:wrap;background:#f5f9f6;border-bottom:1px solid #e0eae4}
  .tw-detail .detail-head h3{font-size:18px;line-height:1.35;margin:0;overflow-wrap:anywhere}
  .tw-detail .back{background:#fff;border:1px solid #cbdacf;border-radius:8px;min-height:36px;padding:7px 12px;flex:none}
  .tw-detail-body{padding:22px;line-height:1.65;color:#243e32}
  .tw-detail-body>p:first-child{padding:10px 13px;background:#f4f7f5;border:1px solid #e1e9e4;border-radius:9px;margin:0 0 14px;color:#4a6254}
  .tw-detail-link{display:inline-flex;align-items:center;min-height:36px;padding:7px 12px;border:1px solid #bad4c4;border-radius:8px;background:#edf7f1;color:#175538;text-decoration:none;font-size:12px;font-weight:650;margin-bottom:12px}
  .tw-detail-link:hover{background:#dff0e5}
  .tw-detail-body>h3{font-size:14px;margin:18px 0 14px;padding-top:18px;border-top:1px solid #e2ebe6}
  .tw-comment{padding:16px 18px;border:1px solid #dfe9e2;border-left:3px solid #73a88a;border-radius:10px;background:#fcfefd;margin:0 0 14px;overflow-wrap:anywhere}
  .tw-comment>strong{font-size:13px;color:#17492e}
  .tw-comment>small{display:block;color:#5b7065;font-size:11px;margin-top:3px;font-variant-numeric:tabular-nums}
  .tw-comment>p{white-space:pre-wrap;overflow-wrap:anywhere;margin:12px 0 0;font-size:13px;line-height:1.8;color:#293e32}
  .tw-detail-body>.error-text{padding:14px;border-radius:9px;background:#fff0ef;border:1px solid #efccc7}
  @media(max-width:680px){
    .tw-requests{border:0;background:transparent;box-shadow:none;overflow:visible}
    .tw-requests>div{overflow:visible}
    .tw-requests .table,.tw-requests .table tbody{display:block;border:0;background:transparent}
    .tw-requests .table caption{display:block;padding:8px 0 16px;border:0;background:transparent}
    .tw-requests .table thead{position:absolute;width:1px;height:1px;padding:0;overflow:hidden;clip-path:inset(50%);white-space:nowrap}
    .tw-requests .table tbody tr{display:block;border:1px solid #dce7df;border-radius:12px;overflow:hidden;margin-bottom:14px;background:#fff!important;box-shadow:0 2px 8px #16342b05}
    .tw-requests .table td,.tw-requests .table td.actions{display:grid;grid-template-columns:112px minmax(0,1fr);align-items:center;gap:12px;padding:11px 14px;min-width:0;white-space:normal;border-bottom:1px solid #f0f4f1}
    .tw-requests .table td::before{content:attr(data-label);font-size:11px;font-weight:600;color:#5b7065}
    .tw-requests .table td.actions{display:flex;flex-wrap:wrap;gap:6px}
    .tw-requests .table td.actions::before{width:106px;flex:none}
    .tw-detail .detail-head{padding:15px;gap:10px}
    .tw-detail-body{padding:16px}
    .tw-comment{padding:13px 14px}
  }
  `;

  const fab = el('button', null, root, 'fab');
  fab.type = 'button';
  const fabDot = el('span', null, fab, 'fab-dot pending');
  const fabLabel = el('span', 'Formulário de solicitações BR', fab, 'fab-label');
  fab.title = 'Formulário de solicitações BR';
  fab.setAttribute('aria-label', fab.title);
  fab.setAttribute('aria-haspopup','dialog');
  fabLabel.id = 'tw-forms-launcher-tooltip';
  fabLabel.setAttribute('role','tooltip');
  fab.setAttribute('aria-describedby',fabLabel.id);
  fabDot.setAttribute('aria-hidden','true');
  const formsIcon = document.createElementNS('http://www.w3.org/2000/svg','svg');
  for (const [k,v] of Object.entries({viewBox:'0 0 24 24',width:'21',height:'21',fill:'none',stroke:'currentColor','stroke-width':'1.65','stroke-linecap':'round','stroke-linejoin':'round','aria-hidden':'true'})) formsIcon.setAttribute(k,v);
  for (const d of ['M8 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2','M9 2h6v4H9z','m7 11 1 1 2-2','M13 11h4','m7 17 1 1 2-2','M13 17h4']) {
    const path = document.createElementNS(formsIcon.namespaceURI,'path');path.setAttribute('d',d);formsIcon.append(path);
  }
  fab.prepend(formsIcon);
  // Stay hidden until the real permalink control is located; no zoom-area fallback.
  fab.setAttribute('data-anchor-missing','');
  css.textContent += `
  .fab{position:fixed!important;left:var(--tw-link-x,0px)!important;top:var(--tw-link-y,0px)!important;
    right:auto!important;bottom:auto!important;width:36px;height:28px;padding:0!important;
    display:flex;align-items:center;justify-content:center;gap:0;border:1px solid #d2d9dc;
    border-radius:16px;background:#fff;color:#34434a;box-shadow:0 1px 4px #0002;overflow:visible}
  .fab[data-anchor-missing]{display:none!important}
  .fab:hover{background:#e8edef;color:#26343b}
  .fab:focus-visible{outline:2px solid #526c78;outline-offset:3px}
  .fab .fab-dot{position:absolute;right:3px;bottom:3px;width:6px;height:6px;box-shadow:0 0 0 2px #fff}
  .fab .fab-dot.ok{background:#35bd78}.fab .fab-dot.pending{background:#edbb38}
  .fab .fab-label{position:absolute;top:50%;right:calc(100% + 10px);transform:translateY(-50%);
    width:max-content;max-width:min(270px,calc(100vw - 78px));padding:8px 11px;border-radius:7px;
    background:#34434a;color:#fff;font:12px/1.5 system-ui,sans-serif;box-shadow:0 3px 12px #0003;
    opacity:0;visibility:hidden;pointer-events:none}
  .fab:hover .fab-label,.fab:focus-visible .fab-label{opacity:1;visibility:visible}
  `;

  const permalinkLauncher = (() => {
    const selector='.bottom.overlay-buttons-container';
    const launcherHost=document.createElement('div');launcherHost.id='tw-forms-inline-launcher';
    launcherHost.style.cssText='display:flex!important;position:relative!important;flex:0 0 auto!important;align-items:center!important;justify-content:center!important;align-self:center!important;margin:4px 0 8px!important;padding:0!important;width:36px!important;height:34px!important;min-height:34px!important;opacity:1!important;visibility:visible!important;';
    const launcherRoot=launcherHost.attachShadow({mode:'open'}),style=document.createElement('style');
    style.textContent=`
    :host{color:#000;font:13px/1.4 system-ui,sans-serif}*{box-sizing:border-box}
    .fab{all:unset;box-sizing:border-box!important;position:relative!important;display:flex!important;align-items:center!important;justify-content:center!important;width:36px!important;height:34px!important;min-width:36px!important;padding:0!important;margin:0!important;border:1.5px solid #000!important;border-radius:9px!important;background:#fff!important;color:#000!important;opacity:1!important;visibility:visible!important;box-shadow:0 1px 4px #0005!important;cursor:pointer;overflow:visible!important}
    .fab:hover{background:#fff!important;box-shadow:0 0 0 2px #0003!important}.fab:focus-visible{outline:2px solid #000;outline-offset:3px}
    .fab>svg{display:block!important;width:22px!important;height:22px!important;fill:none!important;stroke:#000!important;opacity:1!important;visibility:visible!important}
    .fab>svg path{fill:none!important;stroke:#000!important;stroke-width:1.9!important;stroke-linecap:round;stroke-linejoin:round;opacity:1!important;visibility:visible!important}
    .fab-dot{position:absolute;width:7px;height:7px;right:2px;bottom:2px;border:1px solid #000;border-radius:50%;background:#eab308;box-shadow:0 0 0 2px #fff;pointer-events:none}.fab-dot.ok{background:#22c55e}.fab-dot.pending{background:#eab308}.fab-dot.error{background:#ef4444}
    .fab-label{position:absolute;right:calc(100% + 10px);top:50%;transform:translateY(-50%);display:block;width:max-content;max-width:min(270px,calc(100vw - 90px));padding:8px 11px;border-radius:7px;border:1px solid #000;background:#fff;color:#000;font:12px/1.5 system-ui,sans-serif;box-shadow:0 2px 8px #0003;opacity:0;visibility:hidden;pointer-events:none;z-index:100}.fab:hover .fab-label,.fab:focus-visible .fab-label{opacity:1;visibility:visible}
    `;
    launcherRoot.append(style,fab);fab.removeAttribute('data-anchor-missing');fab.removeAttribute('title');
    let container=null,task=null;
    function find(r=document){const d=r.querySelector(selector);if(d)return d;for(const n of r.querySelectorAll('*')){if(n===host||n===launcherHost)continue;if(n.shadowRoot){const m=find(n.shadowRoot);if(m)return m;}}return null;}
    function mount(){task=null;const t=find();if(!t){fab.dataset.positionMode='waiting-for-container';return false;}container=t;if(launcherHost.parentNode!==t)t.prepend(launcherHost);fab.dataset.positionMode='inside-bottom-controls';return true;}
    const observer=new MutationObserver(records=>{if(launcherHost.isConnected&&container?.isConnected&&launcherHost.parentNode===container)return;if(task===null&&records.some(r=>r.addedNodes.length||r.removedNodes.length))task=setTimeout(mount,200);});
    observer.observe(document.body,{subtree:true,childList:true});mount();
    return {refresh:mount,diagnostic:()=>({mode:fab.dataset.positionMode,mounted:launcherHost.isConnected,parent:launcherHost.parentElement?.className||null})};
  })();

  const overlay = el('div', null, root, 'overlay');
  overlay.hidden = true;

  const dialog = el('section', null, overlay, 'dialog');
  dialog.setAttribute('role', 'dialog');
  dialog.setAttribute('aria-modal', 'true');
  dialog.setAttribute('aria-labelledby', 'tw3-title');

  const header = el('header', null, dialog);
  const title = el('h2', 'Solicitações', header);
  title.id = 'tw3-title';

  el('span', 'Comunidade BR · ' + VERSION, header, 'muted');
  el('span', null, header, 'spacer');

  const closeButton = el('button', '✕', header, 'close-x');
  closeButton.setAttribute('aria-label', 'Fechar');

  const scroll = el('div', null, dialog, 'scroll');

  const tabBar = el('div', null, scroll, 'tabbar');
  const tabForm = el('button', 'Solicitações', tabBar, 'tab active');
  const tabMine = el('button', 'Minhas solicitações', tabBar, 'tab');
  const tabSettings = el('button', 'Conexão', tabBar, 'tab');
  tabForm.type = 'button';
  tabMine.type = 'button';
  tabSettings.type = 'button';

  let currentTab = 'form';

  const settings = el('section', null, scroll, 'card');
  settings.hidden = true;

  el('h3', 'Conexão com o site ToolboxWaze', settings);
  const connText = el('p', 'Não conectado.', settings, 'muted');
  el('p', 'Para enviar solicitações é preciso estar conectado: você confirma o seu e-mail uma única vez e este navegador passa a usar um token aleatório gerado pelo script. Nenhuma senha, credencial ou cookie do Waze é enviado.', settings, 'muted');

  const settingsRow = el('div', null, settings, 'row');
  const retryButton = el('button', 'Tentar novamente', settingsRow, 'primary');
  const disconnectButton = el('button', 'Desconectar este navegador', settingsRow);
  const analyticsButton = el('button', 'Estatísticas anônimas: desativadas', settingsRow);
  retryButton.hidden = true;

  const status = el('div', null, scroll, 'status');
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');
  status.hidden = true;

  const stepsCard = el('section', null, scroll, 'card steps');
  stepsCard.hidden = true;

  el('h3', 'Como conectar (leva cerca de 1 minuto)', stepsCard);
  const stepsList = el('ol', null, stepsCard);

  const STEPS = [
    ['Informe seu e-mail', 'Usamos o apelido detectado no WME e o seu e-mail para criar (ou localizar) sua conta no ToolboxWaze.'],
    ['Confirme no e-mail', 'Abra a mensagem do ToolboxWaze e clique em “Confirmar e-mail”. O link vale 60 minutos e só funciona uma vez.'],
    ['Volte ao WME', 'O script detecta a confirmação sozinho (ou use “Já confirmei”). Este navegador fica conectado.'],
    ['Escolha o formulário e envie', 'Depois disso você não precisa repetir o processo neste navegador.']
  ];

  const stepItems = STEPS.map(([titleText, description], index) => {
    const item = el('li', null, stepsList);
    el('span', String(index + 1), item, 'num');
    const box = el('div', null, item);
    el('strong', titleText, box);
    el('span', description, box, 'muted');
    return item;
  });

  const why = el('details', null, stepsCard, 'why');
  el('summary', 'Por que isso é necessário?', why);
  el('p', 'O ToolboxWaze precisa saber quem enviou cada solicitação para que os responsáveis possam responder e para evitar spam e abuso. O script não consegue provar quem você é no Waze, por isso confirmamos o seu e-mail uma única vez. Nenhuma senha nem cookie do Waze é enviado; o token fica guardado só neste navegador.', why);

  const registerCard = el('section', null, scroll, 'card');
  registerCard.hidden = true;

  el('h3', 'Passo 1 de 4 · Informe seu e-mail', registerCard);
  el('p', 'Se você ainda não tem conta no ToolboxWaze, ela é criada automaticamente. Em seguida enviaremos um link para confirmar que o e-mail é seu.', registerCard, 'muted');

  const nickField = el('div', null, registerCard, 'field');
  const nickLabel = el('label', 'Apelido no WME', nickField);
  const nickInput = el('input', null, nickField);
  nickInput.id = 'tw4-nick';
  nickLabel.htmlFor = 'tw4-nick';
  nickInput.maxLength = 60;
  nickInput.autocomplete = 'off';

  const nickHint = el('small', '', nickField);
  const nickError = el('div', '', nickField, 'error-text');
  nickError.setAttribute('role', 'alert');

  const mailField = el('div', null, registerCard, 'field');
  const mailLabel = el('label', 'E-mail', mailField);
  const mailInput = el('input', null, mailField);
  mailInput.id = 'tw4-email';
  mailLabel.htmlFor = 'tw4-email';
  mailInput.type = 'email';
  mailInput.autocomplete = 'email';
  mailInput.maxLength = 180;

  const mailError = el('div', '', mailField, 'error-text');
  mailError.setAttribute('role', 'alert');

  const nameField = el('div', null, registerCard, 'field');
  const nameLabel = el('label', 'Nome (opcional)', nameField);
  const nameInput = el('input', null, nameField);
  nameInput.id = 'tw4-name';
  nameLabel.htmlFor = 'tw4-name';
  nameInput.maxLength = 120;
  nameInput.autocomplete = 'name';

  const consent = el('label', null, registerCard, 'choice');
  const consentBox = el('input', null, consent);
  consentBox.type = 'checkbox';
  el('span', 'Concordo em enviar meu e-mail, meu apelido do WME e os dados das solicitações ao ToolboxWaze.', consent);

  const registerRow = el('div', null, registerCard, 'row');
  registerRow.style.marginTop = '12px';
  const registerButton = el('button', 'Conectar', registerRow, 'primary');

  const waitCard = el('section', null, scroll, 'card');
  waitCard.hidden = true;

  el('h3', 'Passo 2 de 4 · Confirme seu e-mail', waitCard);
  const waitText = el('p', '', waitCard);
  el('p', 'O link vale por 60 minutos. Depois de confirmar, o script detecta sozinho (ou use “Já confirmei”). Não chegou? Olhe a caixa de spam ou use “Reenviar e-mail” (1 vez a cada 5 minutos).', waitCard, 'muted');

  const waitRow = el('div', null, waitCard, 'row');
  const verifyButton = el('button', 'Já confirmei', waitRow, 'primary');
  const resendButton = el('button', 'Reenviar e-mail', waitRow);
  const changeButton = el('button', 'Usar outro e-mail', waitRow);

  const mineCard = el('section', null, scroll, 'card tw-requests');
  mineCard.hidden = true;
  const mineList = el('div', null, mineCard);

  const detailCard = el('section', null, scroll, 'card tw-detail');
  detailCard.hidden = true;
  const detailHead = el('div', null, detailCard, 'detail-head');
  const detailBack = el('button', '← Voltar', detailHead, 'back');
  detailBack.type = 'button';
  detailBack.onclick = () => showMineList();
  const detailTitle = el('h3', 'Minhas solicitações', detailHead);
  const detailBody = el('div', null, detailCard, 'tw-detail-body');

  const pendingBox = el('div', null, scroll, 'card');
  pendingBox.hidden = true;
  el('p', 'Há um envio sem resultado confirmado. Consulte antes de enviar novamente.', pendingBox);
  const checkButton = el('button', 'Consultar resultado do envio', pendingBox);

  const layout = el('div', null, scroll, 'layout');
  const side = el('aside', null, layout, 'side');
  const main = el('div', null, layout);

  const account = el('div', null, side, 'card');
  el('h3', 'Sua conexão', account);
  const accountText = el('p', 'Desconectado', account, 'muted');
  const accountStatus = el('p', '—', account, 'muted');
  el('small', 'Telemetria de uso ativa: eventos e tempos enviados ao projeto e ao GA4 configurado.', account);
  const accountDisconnect = el('button', 'Desconectar este navegador', account);
  accountDisconnect.type = 'button';
  accountDisconnect.hidden = true;
  accountDisconnect.style.marginTop = '10px';

  const contextBox = el('section', null, side, 'card green');
  el('h3', 'Contexto do WME', contextBox);
  const summary = el('pre', 'Aguardando SDK…', contextBox);

  const contextRow = el('div', null, contextBox, 'row');
  contextRow.style.marginTop = '12px';
  const refresh = el('button', 'Atualizar', contextRow);
  const copyButton = el('button', 'Copiar', contextRow);
  const contextNotice = el('p', 'O bloqueio do objeto não é o nível do editor.', contextBox, 'muted');

  const typeCard = el('section', null, main, 'card');
  el('h3', '1. Escolha a solicitação', typeCard);
  const typeList = el('select', null, typeCard, 'types');
  typeList.setAttribute('aria-label', 'Escolher tipo de solicitação');

  const typePlaceholder = el('option', 'Escolher solicitação…', typeList);
  typePlaceholder.value = '';

  const formCard = el('section', null, main, 'card');
  const formTitle = el('h3', '2. Dados da solicitação', formCard);
  const formDescription = el('p', 'Conecte para carregar os formulários disponíveis.', formCard, 'muted');
  const fieldArea = el('div', null, formCard);

  const footer = el('footer', null, dialog);
  const draftNote = el('small', 'Rascunho local por formulário', footer);
  el('span', null, footer, 'spacer');
  const footerClose = el('button', 'Fechar', footer, 'footer-close');
  const clearButton = el('button', 'Limpar rascunho', footer);
  const sendButton = el('button', 'Enviar solicitação', footer, 'primary');
  sendButton.disabled = true;

  const previews = new Set();

  function confirmDialog(title, message, confirmLabel = 'Confirmar') {
    return new Promise(resolve => {
      const box = el('div', null, root, 'confirm-overlay');
      const card = el('div', null, box, 'confirm-box');

      el('h3', title, card);
      el('p', message, card);

      const actions = el('div', null, card, 'row');
      const cancel = el('button', 'Cancelar', actions);
      const ok = el('button', confirmLabel, actions, 'primary');

      const finish = value => {
        box.remove();
        resolve(value);
      };

      cancel.onclick = () => finish(false);
      ok.onclick = () => finish(true);
      box.onclick = event => {
        if (event.target === box) finish(false);
      };

      ok.focus();
    });
  }

  function notice(text, type = '') {
    status.textContent = text;
    status.className = 'status ' + (type || (text ? 'info' : ''));
    status.hidden = !text;
  }

  function updateAnalyticsButton() {
    analyticsButton.textContent = 'Telemetria de uso ativa';
    analyticsButton.disabled = true;
    analyticsButton.title = 'Eventos de uso e tempos são enviados ao servidor do projeto e ao GA4 configurado. Não inclua dados pessoais nos eventos.';
  }

  function showTab(tab) {
    currentTab = tab;

    tabForm.classList.toggle('active', tab === 'form');
    tabMine.classList.toggle('active', tab === 'mine');
    tabSettings.classList.toggle('active', tab === 'settings');

    applyView();
  }

  function applyView() {
    const isForm = currentTab === 'form';
    const isMine = currentTab === 'mine';
    const isSettings = currentTab === 'settings';

    mineCard.hidden = !isMine;
    detailCard.hidden = true;
    layout.hidden = !isForm || conn !== 'ativo';
    pendingBox.hidden = !isForm || !unresolved || conn !== 'ativo';

    settings.hidden = conn === 'ativo' || (!isSettings && conn !== 'erro');
    stepsCard.hidden = !(isSettings && (conn === 'registro' || conn === 'pendente'));
    registerCard.hidden = !(isSettings && conn === 'registro');
    waitCard.hidden = !(isSettings && conn === 'pendente');

    if (isMine && !mineList.children.length) {
      renderMine();
    }

    syncSteps();
    updateBusy();
  }

  const submissionCooldowns = new Map();
  let cooldownTick = null;
  function cooldownKey() {
    return identity && definition ? String(identity.id) + ':' + definition.slug : '';
  }
  function cooldownRemaining() {
    return Math.max(0, Math.ceil(((submissionCooldowns.get(cooldownKey()) || 0) - Date.now()) / 1000));
  }
  function refreshCooldownTimer() {
    clearTimeout(cooldownTick);
    cooldownTick = null;
    if (modalOpen && !document.hidden && cooldownRemaining() > 0) {
      cooldownTick = setTimeout(() => { updateBusy(); }, 1000);
    }
  }
  function applySubmissionCooldown(data) {
    const seconds = Number(data?.aguardarSegundos);
    const serverDate = Date.parse(data?.proximoEnvioEm || '');
    const deadline = Number.isFinite(seconds) && seconds > 0
      ? Date.now() + seconds * 1000
      : serverDate;
    const key = cooldownKey();
    if (key && Number.isFinite(deadline) && deadline > Date.now()) {
      submissionCooldowns.set(key, deadline);
    }
  }

  function updateBusy() {
    const sendDisabled = cooldownRemaining() > 0 || busy || conn !== 'ativo' || !identity || !definition || !definition.campos?.length || Boolean(unresolved);

    sendButton.disabled = sendDisabled;
    refreshCooldownTimer();
    sendButton.classList.toggle('sending', busy);
    sendButton.replaceChildren();
    if (busy) el('span', null, sendButton, 'spinner');
    const remaining = cooldownRemaining();
    const countdown = String(Math.floor(remaining / 60)).padStart(2, '0') + ':' + String(remaining % 60).padStart(2, '0');
    sendButton.append(document.createTextNode(busy ? 'Processando…' : remaining > 0 ? 'Enviar novamente em ' + countdown : 'Enviar solicitação'));

    sendButton.title = !identity || conn !== 'ativo'
      ? 'Conecte-se para enviar uma solicitação.'
      : !definition || !definition.campos?.length
        ? 'Escolha um formulário.'
        : unresolved
          ? 'Consulte o envio pendente antes de enviar novamente.'
          : '';

    fabDot.className = 'fab-dot ' + ({
      ativo: 'ok',
      pendente: 'pending',
      carregando: 'pending',
      registro: 'error',
      erro: 'error'
    }[conn] || 'pending');
    registerButton.disabled = busy;
    verifyButton.disabled = busy;
    resendButton.disabled = busy;
    changeButton.disabled = busy;
    disconnectButton.disabled = busy;
    retryButton.disabled = busy;
    clearButton.disabled = busy;
    refresh.disabled = busy;
    pendingBox.hidden = currentTab !== 'form' || !unresolved || conn !== 'ativo';
    accountDisconnect.disabled = busy;
    clearButton.hidden = currentTab !== 'form' || conn !== 'ativo';
    sendButton.hidden = currentTab !== 'form' || conn !== 'ativo';
    draftNote.hidden = currentTab !== 'form' || conn !== 'ativo';
  }

  function nonJsonMessage(response, contentType, path) {
    const finalUrl = String(response.finalUrl || '');
    const body = String(response.responseText || '');
    const snippet = body
      .replace(/<script[\s\S]*?<\/script>|<style[\s\S]*?<\/style>/gi, ' ')
      .replace(/<[^>]+>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 160);

    warn('Resposta não-JSON', {
      path,
      status: response.status,
      finalUrl,
      contentType,
      trecho: snippet
    });

    if (/\/login(?:[/?#]|$)/.test(finalUrl)) {
      return 'O servidor redirecionou ' + path + ' para a tela de login. A rota /api/wme não está liberada no servidor: confira config/packages/security.yaml, o WmeDeviceAuthenticator em services.yaml e rode cache:clear.';
    }

    if (/html/i.test(contentType) || /^\s*</.test(body)) {
      return 'O servidor devolveu uma página HTML (HTTP ' + response.status + ') em vez de JSON em ' + path + (snippet ? '. Trecho: “' + snippet + '”' : '') + '. Detalhes no console (F12).';
    }

    return 'Resposta inesperada (HTTP ' + response.status + ') em ' + path + '. Detalhes no console (F12).';
  }

  function shouldTrackApiTiming(path) {
    return /^\/(bootstrap|formularios\/|solicitacoes(\/|$)|duplicatas|envios\/)/.test(path);
  }

  function api(method, path, body) {
    const credential = token;

    return new Promise((resolve, reject) => {
      const start = performance.now();
      log('HTTP', method, path);

      const multipart = body instanceof FormData;

      GM_xmlhttpRequest({
        method,
        url: API + path,
        anonymous: true,
        timeout: 25000,
        headers: {
          Accept: 'application/json',
          'X-API-TOKEN': credential,
          ...(body && !multipart ? { 'Content-Type': 'application/json' } : {})
        },
        data: body ? (multipart ? body : JSON.stringify(body)) : undefined,
        onload: (response) => {
          if (response.status >= 400) {
            const hideToken = value => {
                let text = String(value ?? '');
        
                if (credential) {
                    text = text.split(credential).join('[TOKEN REMOVIDO]');
                }
        
                return text;
            };
        
            const contentType = (
                /content-type:\s*([^\r\n]+)/i.exec(
                    response.responseHeaders || ''
                ) || []
            )[1] || '(não informado)';
        
            console.group('[ToolboxWaze DIAGNOSTICO-V1] HTTP ' + response.status);
        
            console.error('Método:', method);
            console.error('URL enviada:', hideToken(API + path));
            console.error('URL final:', hideToken(response.finalUrl || API + path));
            console.error('Content-Type:', contentType);
        
            console.error(
                'Resposta do servidor:',
                hideToken(response.responseText).slice(0, 8000)
            );
        
            console.groupEnd();
        }
          const duration = Math.round(performance.now() - start);

          log('HTTP', response.status, path, duration + 'ms');

          if (shouldTrackApiTiming(path)) {
            trackTiming('api_request', duration, {
              endpoint: path,
              method,
              http_status: response.status
            });
          }

          let data = null;
          try {
            data = JSON.parse(response.responseText);
          } catch {}

          const contentType = (/content-type:\s*([^\r\n;]+)/i.exec(response.responseHeaders || '') || [])[1]?.trim() || '';

          if (response.status < 200 || response.status >= 300) {
            trackEvent('api_error', {
              endpoint: path,
              method,
              http_status: response.status,
              error_code: data?.codigo || ''
            });

            const error = new Error(
              data?.message ||
              data?.error ||
              (data && typeof data === 'object' ? 'HTTP ' + response.status : nonJsonMessage(response, contentType, path))
            );

            error.status = response.status;
            error.codigo = data?.codigo || '';
            error.errors = data?.errors || {};
            error.data = data || {};
            if (error.codigo === 'intervalo_envio') {
              applySubmissionCooldown(error.data);
              updateBusy();
            }

            reject(error);
            return;
          }

          if (!data || typeof data !== 'object') {
            const error = new Error(nonJsonMessage(response, contentType, path));
            error.uncertain = true;
            error.status = response.status;

            reject(error);
            return;
          }

          resolve(data);
        },
        onerror: () => reject(Object.assign(new Error('Falha de rede. O servidor pode ter recebido o envio.'), { uncertain: true })),
        ontimeout: () => reject(Object.assign(new Error('Tempo esgotado. Consulte o resultado antes de reenviar.'), { uncertain: true }))
      });
    });
  }

  function getUserState() {
    try {
      const info = sdk?.State?.getUserInfo?.();

      return info?.stateCode || info?.regionCode || info?.state || info?.region || null;
    } catch {
      return null;
    }
  }

  function selection() {
    try {
      const selected = sdk?.Editing?.getSelection?.();

      if (selected && Array.isArray(selected.ids)) {
        return selected.ids.map(id => ({
          id: String(id),
          type: String(selected.objectType).toLowerCase()
        }));
      }
    } catch (error) {
      warn('Seleção:', error.message);
    }

    return [];
  }

  function collect() {
    const selected = selection();

    const objects = selected.slice(0, 50).map(item => {
      const object = { ...item };

      try {
        const wmeObject = item.type === 'segment'
          ? sdk.DataModel.Segments.getById({ segmentId: Number(item.id) })
          : item.type === 'venue'
            ? sdk.DataModel.Venues.getById({ venueId: item.id })
            : null;

        if (wmeObject) {
          object.name = wmeObject.name || null;
          object.roadType = wmeObject.roadType ?? null;
          object.lockLevel = Number.isFinite(wmeObject.lockLevel) ? wmeObject.lockLevel : null;

          if (wmeObject.primaryStreetId != null) {
            object.streetName = sdk.DataModel.Streets.getById({ streetId: wmeObject.primaryStreetId })?.name || null;
          }
        }
      } catch (error) {
        warn('Leitura parcial:', item.id, error.message);
      }

      return object;
    });

    let center = null;
    let zoom = null;

    try {
      center = sdk.Map.getMapCenter();
      zoom = sdk.Map.getZoomLevel();
    } catch {}

    const url = new URL(location.href);
    url.hash = '';

    ['tab', 's', 'marker', 'segments', 'venues'].forEach(key => url.searchParams.delete(key));

    if (Number.isFinite(center?.lat) && Number.isFinite(center?.lon)) {
      url.searchParams.set('lat', center.lat);
      url.searchParams.set('lon', center.lon);
    }

    if (Number.isFinite(zoom)) {
      url.searchParams.delete('zoom');
      url.searchParams.set('zoomLevel', zoom);
    }

    for (const [type, key] of [['segment', 'segments'], ['venue', 'venues']]) {
      const ids = objects.filter(object => object.type === type).map(object => object.id);

      if (ids.length) {
        url.searchParams.set(key, ids.join(','));
      }
    }

    return {
      capturadoEm: new Date().toISOString(),
      permalink: url.href,
      center,
      zoom,
      totalSelecionados: selected.length,
      objetos: objects
    };
  }

  function summarize() {
    if (!context) return '📍 SDK ainda indisponível. Você pode preencher os campos manualmente.';

    const segmentCount = context.objetos.filter(object => object.type === 'segment').length;
    const venueCount = context.objetos.filter(object => object.type === 'venue').length;

    return [
      '📍 Posição: ' + (
        Number.isFinite(context.center?.lat) && Number.isFinite(context.center?.lon)
          ? context.center.lat.toFixed(5) + ', ' + context.center.lon.toFixed(5)
          : 'indisponível'
      ),
      '🔍 Zoom: ' + (context.zoom ?? 'indisponível'),
      '🛣️ Segmentos: ' + segmentCount,
      '🏢 POIs: ' + venueCount,
      context.totalSelecionados > 50 ? '⚠️ Contexto limitado a 50 objetos.' : '',
      '🕒 Capturado às ' + new Date(context.capturadoEm).toLocaleTimeString()
    ].filter(Boolean).join('\n');
  }

  function capture() {
    if (!modalOpen) return;

    if (!sdk) {
      notice('SDK ainda não disponível.', 'error');
      return;
    }

    context = collect();
    summary.textContent = summarize();
    contextNotice.textContent = 'Contexto atualizado. Bloqueio do objeto não é nível do editor.';

    if (definition) {
      prefill();
      renderFields();
      scheduleSave();
    }
  }

  function source(path) {
    if (path === 'wme.permalink') return context?.permalink;
    if (path === 'wme.segmentIds') return context?.objetos.filter(object => object.type === 'segment').map(object => object.id).join(',');
    if (path === 'wme.venueName') return context?.objetos.find(object => object.type === 'venue')?.name;
    if (path === 'wme.objectLockLevel') return context?.objetos.length === 1 ? context.objetos[0].lockLevel : null;
    if (path === 'account.wazeNickname') return identity?.wazeNickname;
    if (path === 'wme.userState') return getUserState();

    return null;
  }

  function permalinkCheck(text, field) {
    const minimumZoom = field.zoomMinimo || 15;
    let url;

    try {
      url = new URL(text);
    } catch {
      return { ok: false, msg: 'Cole um link completo do WME (começa com https://www.waze.com/…/editor).' };
    }

    if (
      !['https:', 'http:'].includes(url.protocol) ||
      !['www.waze.com', 'beta.waze.com', 'waze.com'].includes(url.hostname) ||
      !/^\/(?:[a-zA-Z-]+\/)?editor\/?$/.test(url.pathname)
    ) {
      return { ok: false, msg: 'O link precisa ser um permalink do Waze Map Editor.' };
    }

    const lat = Number(url.searchParams.get('lat'));
    const lon = Number(url.searchParams.get('lon'));

    if (
      !url.searchParams.has('lat') ||
      !url.searchParams.has('lon') ||
      !Number.isFinite(lat) ||
      !Number.isFinite(lon) ||
      Math.abs(lat) > 90 ||
      Math.abs(lon) > 180
    ) {
      return { ok: false, msg: 'O link não tem coordenadas (lat/lon). Use “Usar posição atual do mapa”.' };
    }

    const rawZoom = url.searchParams.get('zoomLevel') ?? url.searchParams.get('zoom');
    const zoom = Number(rawZoom);

    if (rawZoom == null || rawZoom === '' || !Number.isFinite(zoom)) {
      return { ok: false, msg: 'O link não informa o zoom. Use “Usar posição atual do mapa”.' };
    }

    if (zoom < minimumZoom) {
      return { ok: false, msg: 'Zoom ' + zoom + ' é menor que o mínimo (' + minimumZoom + '). Aproxime o mapa para enxergar os pontos com detalhe e gere o link de novo.' };
    }

    return { ok: true, msg: 'Link válido · zoom ' + zoom + ' · ' + lat.toFixed(5) + ', ' + lon.toFixed(5) };
  }

  function prefill() {
    for (const field of definition.campos) {
      if (field.preenchimento && (answers[field.chave] == null || answers[field.chave] === '')) {
        const value = source(field.preenchimento.origem);

        if (value == null) continue;

        if (field.validacao === 'permalink' && !permalinkCheck(String(value), field).ok) continue;

        answers[field.chave] = value;
      }
    }
  }

  function visible(field, visited = new Set()) {
    if (visited.has(field.chave)) return false;

    visited.add(field.chave);

    return !field.mostrarSe || Object.entries(field.mostrarSe).every(([key, value]) => {
      const dependency = definition.campos.find(item => item.chave === key);

      return Boolean(dependency) &&
        visible(dependency, new Set(visited)) &&
        (Array.isArray(value) ? value.includes(answers[key]) : answers[key] === value);
    });
  }

  function revokePreviews() {
    for (const url of previews) URL.revokeObjectURL(url);
    previews.clear();
  }

  function fieldError(key, text) {
    const box = Array.from(fieldArea.children).find(node => node.dataset.key === key);

    if (!box) return;

    const error = box.querySelector('.error-text');
    error.textContent = text;

    box.querySelectorAll('input,textarea,select').forEach(node => {
      node.classList.add('bad');
      node.setAttribute('aria-invalid', 'true');
    });
  }

  function renderFields() {
    revokePreviews();
    fieldArea.replaceChildren();

    if (!definition) return;

    for (const field of definition.campos) {
      if (!visible(field)) continue;

      const box = el('div', null, fieldArea, 'field');
      box.dataset.key = field.chave;

      const id = 'tw3-field-' + field.chave;
      const label = el('label', field.label + (field.obrigatorio ? ' *' : ''), box);
      label.htmlFor = id;

      const value = answers[field.chave];
      let input;

      const changed = newValue => {
        answers[field.chave] = newValue;
        dirty = true;
        scheduleSave();
        liveValidate(field.chave);

        if (definition.campos.some(item => item.mostrarSe && Object.hasOwn(item.mostrarSe, field.chave))) {
          renderFields();
        }
      };

      if (field.tipo === 'radio') {
        const group = el('div', null, box, 'choices');
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-label', field.label);

        for (const option of field.opcoes || []) {
          const choice = el('label', null, group, 'choice');
          const radio = el('input', null, choice);

          radio.type = 'radio';
          radio.name = id;
          radio.value = option.value;
          radio.checked = value === option.value;

          el('span', option.label, choice);
          radio.onchange = () => changed(option.value);
        }
      } else if (field.tipo === 'checkbox') {
        input = el('input', null, box);
        input.type = 'checkbox';
        input.checked = value === true;
        input.onchange = () => changed(input.checked);
      } else if (field.tipo === 'select') {
        input = el('select', null, box);

        const empty = el('option', 'Escolher', input);
        empty.value = '';

        for (const option of field.opcoes || []) {
          const optionNode = el('option', option.label, input);
          optionNode.value = option.value;
        }

        input.value = value ?? '';
        input.onchange = () => changed(input.value);
      } else if (field.tipo === 'file') {
        input = el('input', null, box);
        input.type = 'file';
        input.multiple = (field.maxArquivos || 1) > 1;
        input.accept = (field.mimeTypes || ['image/jpeg', 'image/png', 'image/webp']).join(',');

        input.onchange = () => {
          files[field.chave] = Array.from(input.files || []);
          dirty = true;
          renderFields();
        };

        const list = el('div', null, box, 'preview');

        for (const [index, file] of (files[field.chave] || []).entries()) {
          const figure = el('figure', null, list);

          if (['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
            const image = el('img', null, figure);
            const objectUrl = URL.createObjectURL(file);

            previews.add(objectUrl);
            image.src = objectUrl;
            image.alt = file.name;
          }

          el('small', file.name + ' · ' + Math.ceil(file.size / 1024) + ' KB', figure);

          const remove = el('button', 'Remover', figure);
          remove.onclick = () => {
            files[field.chave].splice(index, 1);
            renderFields();
          };
        }
      } else if (['text', 'textarea', 'number', 'email', 'url', 'date'].includes(field.tipo)) {
        input = el(field.tipo === 'textarea' ? 'textarea' : 'input', null, box);

        if (field.tipo !== 'textarea') input.type = field.tipo;

        input.value = value ?? '';

        if (field.placeholder) input.placeholder = field.placeholder;
        if (field.maxLength) input.maxLength = field.maxLength;
        if (field.min != null) input.min = field.min;
        if (field.max != null) input.max = field.max;

        let counter = null;

        if (field.tipo === 'textarea') {
          counter = el('small', String(input.value.length) + ' caracteres', box);
        }

        input.oninput = () => {
          changed(input.value);

          if (counter) {
            counter.textContent = input.value.length + (field.maxLength ? ' / ' + field.maxLength : '') + ' caracteres';
          }
        };
      } else {
        el('p', 'Tipo não suportado: ' + field.tipo + '. Atualize o userscript.', box, 'error-text');
      }

      if (input) {
        input.id = id;
        input.setAttribute('aria-describedby', id + '-error');
      }

      if (field.ajuda) el('p', field.ajuda, box, 'muted');

      if (field.validacao === 'permalink' && input && field.tipo !== 'file') {
        const hint = el('div', '', box, 'hint');

        const updateHint = () => {
          const text = String(answers[field.chave] ?? '').trim();

          if (!text) {
            hint.textContent = 'Exigido: link do WME com zoom ≥ ' + (field.zoomMinimo || 15) + '. A detecção automática usa o segmento selecionado; para áreas maiores, como imagem de satélite, usa a área visível do WME quando o script está aberto.';
            hint.className = 'hint';
            return;
          }

          const check = permalinkCheck(text, field);
          hint.textContent = (check.ok ? '✓ ' : '✗ ') + check.msg;
          hint.className = 'hint ' + (check.ok ? 'ok' : 'bad');
        };

        updateHint();
        input.addEventListener('input', updateHint);
      }

      if (field.preenchimento) {
        const isLink = field.preenchimento.origem === 'wme.permalink';
        const captureButton = el('button', isLink ? 'Usar posição atual do mapa' : 'Preencher do WME / conta', box);
        captureButton.type = 'button';

        captureButton.onclick = () => {
          if (isLink) {
            if (!sdk) {
              notice('SDK do WME indisponível. Cole o permalink manualmente.', 'error');
              return;
            }

            context = collect();
            summary.textContent = summarize();

            if (!context.totalSelecionados) {
              notice('Nenhum segmento selecionado. Para solicitações de segmento, selecione o trecho no WME; para áreas maiores, será usada a área visível do mapa.', 'info');
            }

            const minimumZoom = field.zoomMinimo || 15;

            if (!(Number.isFinite(context.zoom) && context.zoom >= minimumZoom)) {
              notice('Zoom atual: ' + (context.zoom ?? 'desconhecido') + '. Aproxime o mapa até o zoom ' + minimumZoom + ' ou mais e clique de novo.', 'error');
              return;
            }

            const check = permalinkCheck(context.permalink, field);

            if (!check.ok) {
              notice(check.msg, 'error');
              return;
            }

            changed(context.permalink);
            renderFields();
            notice('Permalink atualizado com a posição atual (zoom ' + context.zoom + ').', 'success');

            return;
          }

          const value = source(field.preenchimento.origem);

          if (value != null) {
            changed(value);
            renderFields();
          } else {
            notice('Dado indisponível para este campo. Preencha manualmente.', 'error');
          }
        };
      }

      const error = el('div', '', box, 'error-text');
      error.id = id + '-error';
      error.setAttribute('role', 'alert');
    }

    updateBusy();
  }

  function validate(silent = false) {
    const errors = {};
    const allowed = ['text', 'textarea', 'number', 'email', 'url', 'date', 'select', 'radio', 'checkbox', 'file'];

    for (const field of definition.campos) {
      if (!visible(field)) continue;

      const value = answers[field.chave];
      const text = String(value ?? '').trim();
      let error = '';

      if (!allowed.includes(field.tipo)) {
        error = 'Tipo de campo não suportado.';
      } else if (field.tipo === 'file') {
        const list = files[field.chave] || [];

        if (field.obrigatorio && !list.length) {
          error = 'Anexe um arquivo.';
        } else if (list.length > (field.maxArquivos || 1)) {
          error = 'Quantidade de arquivos excedida.';
        } else if (list.some(file => file.size > (field.maxBytes || 1048576))) {
          error = 'Arquivo maior que o permitido.';
        } else if (list.some(file => !(field.mimeTypes || ['image/jpeg', 'image/png', 'image/webp']).includes(file.type))) {
          error = 'Formato de arquivo não permitido.';
        }
      } else if (field.obrigatorio && (field.tipo === 'checkbox' ? value !== true : !text)) {
        error = 'Campo obrigatório.';
      } else if (text && field.minLength && text.length < field.minLength) {
        error = 'Mínimo de ' + field.minLength + ' caracteres.';
      } else if (text && field.maxLength && text.length > field.maxLength) {
        error = 'Máximo de ' + field.maxLength + ' caracteres.';
      } else if (
        field.tipo === 'number' &&
        text &&
        (
          !Number.isFinite(Number(value)) ||
          (field.inteiro && !Number.isInteger(Number(value))) ||
          (field.min != null && Number(value) < field.min) ||
          (field.max != null && Number(value) > field.max)
        )
      ) {
        error = 'Número fora dos limites permitidos.';
      } else if (['select', 'radio'].includes(field.tipo) && text && !(field.opcoes || []).some(option => option.value === value)) {
        error = 'Opção inválida.';
      } else if (field.valorObrigatorio !== undefined && value !== field.valorObrigatorio) {
        error = 'Confirmação necessária.';
      } else if (field.tipo === 'email' && text && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text)) {
        error = 'E-mail inválido.';
      } else if (field.tipo === 'url' && text) {
        try {
          const url = new URL(text);

          if (!['http:', 'https:'].includes(url.protocol)) {
            throw new Error();
          }
        } catch {
          error = 'URL inválida.';
        }
      }

      if (!error && text && field.validacao === 'permalink') {
        const check = permalinkCheck(text, field);

        if (!check.ok) {
          error = check.msg;
        }
      }

      if (!error && text && field.validacao === 'wkt' && !/^(POLYGON|LINESTRING)\s*\(/i.test(text)) {
        error = 'Use uma geometria WKT válida, por exemplo POLYGON((...)) ou LINESTRING(...).';
      }

      if (error) {
        errors[field.chave] = error;
      }
    }

    if (!silent && Object.keys(errors).length) {
      trackEvent('form_validation_error', {
        form_slug: definition?.slug || '',
        error_count: Object.keys(errors).length
      });
    }

    return errors;
  }

  function liveValidate(key) {
    if (!definition) return;

    const errors = validate(true);
    const box = Array.from(fieldArea.children).find(node => node.dataset.key === key);

    if (!box) return;

    const error = box.querySelector('.error-text');
    const message = errors[key] || '';

    error.textContent = message;
    box.querySelectorAll('input,textarea,select').forEach(node => {
      node.classList.toggle('bad', Boolean(message));
      node.setAttribute('aria-invalid', message ? 'true' : 'false');
    });
  }

  async function draftKey() {
    return 'tw3_draft_' + String(identity.id) + '_' + definition.slug;
  }

  function scheduleSave() {
    if (restoring || !identity || !definition) return;

    clearTimeout(saveTimer);

    const key = 'tw3_draft_' + String(identity.id) + '_' + definition.slug;
    const snapshot = {
      answers: structuredClone(answers),
      context,
      updatedAt: Date.now()
    };

    saveTimer = setTimeout(() => {
      store.set(key, snapshot)
        .then(() => {
          draftNote.textContent = 'Rascunho salvo · ' + new Date().toLocaleTimeString();
        })
        .catch(() => {
          draftNote.textContent = 'Falha ao salvar rascunho';
        });
    }, 350);
  }

  async function choose(slug) {
    if (busy || unresolved) return;

    if (dirty) scheduleSave();

    const serial = ++loadSerial;
    sendButton.disabled = true;
    notice('Carregando formulário…');

    try {
      const data = await api('GET', '/formularios/' + encodeURIComponent(slug));

      if (serial !== loadSerial) return;

      if (
        data.slug !== slug ||
        !Array.isArray(data.campos) ||
        new Set(data.campos.map(field => field.chave)).size !== data.campos.length ||
        data.campos.some(field => !/^[-a-zA-Z0-9_]+$/.test(field.chave))
      ) {
        throw new Error('Definição de formulário inválida.');
      }

      definition = data;
      restoring = true;
      answers = {};
      files = {};

      trackEvent('form_selected', {
        form_slug: data.slug,
        form_version: data.versao || '',
        field_count: data.campos.length
      });

      const saved = await store.get(await draftKey());

      if (saved && Date.now() - saved.updatedAt < 7 * 86400000) {
        answers = saved.answers || {};

        if (saved.context) {
          context = saved.context;
        }
      }

      prefill();
      formTitle.textContent = data.nome;
      formDescription.textContent = data.descricao || '';
      summary.textContent = summarize();
      renderFields();
      dirty = false;
      restoring = false;

      typeList.value = slug;

      log('Campos recebidos', {
        slug,
        quantidade: data.campos.length
      });

      notice(
        data.campos.length ? '' : 'A API retornou este formulário sem campos. Confira a migration no banco do servidor.',
        data.campos.length ? '' : 'error'
      );

      updateBusy();
    } catch (error) {
      restoring = false;
      definition = null;
      notice(error.message, 'error');
      updateBusy();
      authLost(error);
    }
  }

  const AUTH_CODES = [
    'email_nao_confirmado',
    'token_desconhecido',
    'token_invalido',
    'dispositivo_revogado',
    'dispositivo_expirado',
    'nao_autenticado'
  ];

  function authLost(error) {
    if (AUTH_CODES.includes(error?.codigo)) {
      setTimeout(() => boot(), 0);
      return true;
    }

    return false;
  }

  function syncSteps() {
    const current = ({
      registro: 0,
      pendente: 1,
      ativo: 3
    })[conn];

    stepsCard.hidden = !(currentTab === 'settings' && (conn === 'registro' || conn === 'pendente'));

    stepItems.forEach((item, index) => {
      item.classList.toggle('done', current !== undefined && index < current);
      item.classList.toggle('current', current !== undefined && index === current);
    });
  }

  function setConn(state, info) {
    conn = state;

    if (info) pendingInfo = info;

    tabMine.hidden = state !== 'ativo';
    tabSettings.hidden = state === 'ativo';
    if (state === 'ativo' && currentTab === 'settings') currentTab = 'form';
    if (['registro', 'pendente', 'erro'].includes(state)) currentTab = 'settings';
    tabForm.classList.toggle('active', currentTab === 'form');
    tabMine.classList.toggle('active', currentTab === 'mine');
    tabSettings.classList.toggle('active', currentTab === 'settings');

    if (state === 'pendente') {
      waitText.textContent = 'Enviamos um link de confirmação para ' + (pendingInfo?.emailMascarado || 'seu e-mail') + '. Abra o e-mail e clique em “Confirmar e-mail” para liberar o envio de solicitações.';
    }

    connText.textContent = ({
      ativo: 'Conectado.',
      pendente: 'Aguardando a confirmação do e-mail.',
      registro: 'Não conectado.',
      carregando: 'Conectando…',
      erro: 'Sem conexão com o servidor.'
    })[state] || '';

    accountStatus.textContent = ({
      ativo: 'Conectado',
      pendente: 'Aguardando confirmação do e-mail',
      registro: 'Não conectado',
      carregando: 'Conectando…',
      erro: 'Sem conexão com o servidor'
    })[state] || '—';

    accountDisconnect.hidden = state !== 'ativo';

    retryButton.hidden = state !== 'erro';
    disconnectButton.hidden = !token;



    if (state === 'registro' && !overlay.hidden) {
      setTimeout(() => (mailInput.value ? consentBox : mailInput).focus(), 0);
    }

    if (state === 'ativo' && modalOpen) startAlertPoll();
    else stopAlertPoll();

    applyView();
  }

  function prefillNick() {
    let nick = '';

    try {
      nick = sdk?.State?.getUserInfo?.()?.userName || '';
    } catch {}

    if (nick) {
      nickInput.value = nick;
      nickInput.readOnly = true;
      nickHint.textContent = 'Detectado no WME.';
    } else if (!nickInput.value) {
      nickHint.textContent = 'Não foi possível detectar automaticamente. Informe seu apelido no WME.';
    }
  }

  async function dropToken() {
    token = '';

    await store.del(TOKEN_KEY);
    await store.del(LEGACY_KEY);

    identity = null;
    definition = null;
    forms = [];
    answers = {};
    files = {};

    revokePreviews();
    fieldArea.replaceChildren();
    typeList.replaceChildren();
    accountText.textContent = 'Desconectado';
  }

  function startPoll() {
    stopPoll();

    const until = Date.now() + 20 * 60000;

    pollTimer = setInterval(() => {
      if (overlay.hidden || conn !== 'pendente' || Date.now() > until) {
        stopPoll();
        return;
      }

      boot(true);
    }, 6000);
  }

  function stopPoll() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  let alertPollTimer = null;
  let lastSeen = await store.get('tw_alerts_last_seen', null);

  function startAlertPoll() {
    stopAlertPoll();
    if (!modalOpen || document.hidden || conn !== 'ativo') return;
    const tick = async () => {
      await checkAlerts();
      if (modalOpen && !document.hidden && conn === 'ativo') alertPollTimer = setTimeout(tick, 60000);
    };
    tick();
  }

  function stopAlertPoll() {
    if (alertPollTimer) {
      clearTimeout(alertPollTimer);
      alertPollTimer = null;
    }
  }

  let alertsLoading = false;
  async function checkAlerts() {
    if (alertsLoading || !modalOpen || document.hidden || conn !== 'ativo' || !identity) return;
    alertsLoading = true;
    const run = async () => {
      const accountId = String(identity.id);
      const credential = token;
      const key = 'tw_notified_updates_v1_' + accountId;
      const cursorKey = 'tw_alerts_cursor_v1_' + accountId;
      const cursor = await store.get(cursorKey, null);
      const data = await api('GET', '/solicitacoes/alteracoes' + (cursor ? '?since=' + encodeURIComponent(cursor) : ''));
      if (!identity || String(identity.id) !== accountId || credential !== token) return;
      const seen = await store.get(key, {});
      for (const item of (data.alteracoes || [])) {
        const version = item.atualizadoEm;
        if (item.id == null || !version) continue;
        const id = String(item.id);
        const stamp = Date.parse(version);
        const previous = seen[id];
        if (previous && (previous === version || (Number.isFinite(stamp) && stamp <= Date.parse(previous)))) continue;
        seen[id] = version;
        await store.set(key, seen);
        await alerts.notification.add({
          id: 'solicitacao-' + item.id + '-' + version,
          title: 'Solicitação atualizada',
          message: item.resumo || ('#' + item.id + ' — ' + (item.statusLabel || item.status || 'atualizada')),
          buttonText: 'Abrir', action: 'abrir-solicitacao',
          data: { id: item.id, url: requestUrl(item) }
        });
      }
      if (data.servidorEm) {
        lastSeen = data.servidorEm;
        await store.set(cursorKey, lastSeen);
      }
    };
    try {
      if (navigator.locks?.request) await navigator.locks.request('tw-alert-poll', run);
      else await run();
    } catch (error) { warn('Alertas:', error.message); }
    finally { alertsLoading = false; }
  }

  async function applyBootstrap(data) {
    if (data.estado === 'pendente') {
      identity = null;
      definition = null;
      forms = [];
      typeList.replaceChildren();
      accountText.textContent = 'Aguardando confirmação do e-mail';

      setConn('pendente', data.usuario);
      startPoll();

      return;
    }

    if (data.estado !== 'ativo' || !data.usuario?.id || !Array.isArray(data.formularios)) {
      throw new Error('Resposta de bootstrap inválida.');
    }

    stopPoll();
    clearTimeout(saveTimer);

    definition = null;
    answers = {};
    files = {};
    fieldArea.replaceChildren();

    identity = data.usuario;
    forms = data.formularios;

    accountText.textContent = (identity.nome || identity.wazeNickname || 'Usuário') + ' · ' + (identity.wazeNickname || '');
    typeList.replaceChildren();

    const placeholder = el('option', 'Escolher solicitação…', typeList);
    placeholder.value = '';

    for (const form of forms) {
      const option = el('option', form.nome, typeList);
      option.value = form.slug;
    }

    typeList.onchange = () => {
      const slug = typeList.value;

      if (slug) {
        choose(slug);
      }
    };

    setConn('ativo');
    settings.hidden = true;
    syncSteps();

    notice(
      forms.length ? 'Conectado. Escolha um formulário.' : 'Nenhum formulário disponível para sua conta.',
      'success'
    );
  }

  async function boot(silent = false) {
    if (busy) return;

    busy = true;
    updateBusy();

    if (!silent) setConn('carregando');

    try {
      if (!token) {
        setConn('registro');
        return;
      }

      await applyBootstrap(await api('GET', '/bootstrap'));
    } catch (error) {
      if (error.codigo === 'token_desconhecido' || error.codigo === 'token_invalido') {
        stopPoll();
        setConn('registro');
      } else if (error.codigo === 'dispositivo_revogado') {
        stopPoll();
        await dropToken();
        setConn('registro');
        notice('A conexão anterior foi encerrada. Conecte novamente.', 'error');
      } else if (error.codigo === 'dispositivo_expirado') {
        stopPoll();
        await dropToken();
        setConn('registro');
        notice('Este dispositivo expirou por inatividade. Conecte novamente.', 'error');
      } else {
        if (!silent) setConn('erro');
        notice(error.message, 'error');
      }
    } finally {
      busy = false;
      updateBusy();
    }
  }

  async function register() {
    if (busy) return;

    mailError.textContent = '';
    nickError.textContent = '';

    const email = mailInput.value.trim();
    const nick = nickInput.value.trim();
    const nome = nameInput.value.trim();

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      mailError.textContent = 'Informe um e-mail válido.';
      mailInput.focus();
      return;
    }

    if (!nick) {
      nickError.textContent = 'Informe seu apelido no WME.';
      nickInput.focus();
      return;
    }

    if (!consentBox.checked) {
      notice('Marque a concordância para continuar.', 'error');
      consentBox.focus();
      return;
    }

    busy = true;
    updateBusy();
    notice('');

    let retry = false;

    try {
      if (!token) {
        token = randomToken();
        await store.set(TOKEN_KEY, token);
        await store.del(LEGACY_KEY);
      }

      const data = await api('POST', '/registrar', {
        email,
        wazeNickname: nick,
        nome: nome || undefined,
        scriptVersion: VERSION
      });

      setConn('pendente', data);
      startPoll();

      notice(
        data.emailEnviado === false
          ? 'Conta registrada, mas o e-mail não pôde ser enviado agora. Use “Reenviar e-mail”.'
          : 'E-mail de confirmação enviado.',
        data.emailEnviado === false ? 'error' : 'success'
      );
    } catch (error) {
      if (error.errors?.email) mailError.textContent = [].concat(error.errors.email).join(' ');
      if (error.errors?.wazeNickname) nickError.textContent = [].concat(error.errors.wazeNickname).join(' ');

      if (error.codigo === 'token_ja_registrado') retry = true;
      else notice(error.message, 'error');
    } finally {
      busy = false;
      updateBusy();
    }

    if (retry) await boot();
  }

  async function resend() {
    if (busy) return;

    busy = true;
    updateBusy();

    let retry = false;

    try {
      const data = await api('POST', '/verificacao/reenviar');

      if (data.estado === 'ativo') {
        retry = true;
      } else {
        setConn('pendente', data);

        notice(
          data.emailEnviado === false
            ? 'Não foi possível enviar o e-mail agora. Tente novamente em instantes.'
            : 'Novo e-mail enviado.',
          data.emailEnviado === false ? 'error' : 'success'
        );
      }
    } catch (error) {
      if (error.codigo === 'aguarde') {
        const seconds = Math.max(1, Number(error.data?.aguardarSegundos) || 60);
        const text = seconds >= 60 ? Math.ceil(seconds / 60) + ' min' : seconds + ' s';

        notice('Aguarde ' + text + ' para reenviar o e-mail.', 'error');
      } else {
        notice(error.message, 'error');
      }

      authLost(error);
    } finally {
      busy = false;
      updateBusy();
    }

    if (retry) await boot();
  }

  async function disconnect(confirmFirst = true) {
    if (busy) return;

    if (confirmFirst && !(await confirmDialog(
      'Desconectar',
      'Desconectar este navegador do ToolboxWaze? Você precisará confirmar o e-mail novamente.',
      'Desconectar'
    ))) {
      return;
    }

    busy = true;
    updateBusy();
    stopPoll();
    stopAlertPoll();
    clearTimeout(saveTimer);

    try {
      if (token) {
        await api('POST', '/dispositivo/revogar');
      }
    } catch (error) {
      warn('Revogação no servidor falhou:', error.message);
    }

    await dropToken();
    setConn('registro');
    settings.hidden = true;
    syncSteps();
    notice('Desconectado deste navegador.');

    busy = false;
    updateBusy();
  }

  function requestUrl(item) {
    const candidate = item.url || item.link || (item.id != null ? '/solicitacoes/' + encodeURIComponent(String(item.id)) : null);

    if (!candidate) return null;

    try {
      const url = new URL(candidate, BASE);

      return url.origin === new URL(BASE).origin && url.protocol === 'https:' ? url.href : null;
    } catch {
      return null;
    }
  }

  function showMineList() {
    detailCard.hidden = true;
    mineCard.hidden = false;
    detailTitle.textContent = 'Minhas solicitações';
    renderMine();
  }

  async function showStatus(id) {
    currentTab = 'mine';
    mineCard.hidden = true;
    detailCard.hidden = false;
    detailTitle.textContent = 'Solicitação #' + id;
    detailBody.replaceChildren(el('p', 'Carregando detalhes…', null, 'muted'));

    try {
        const data = await api(
          'GET',
          '/solicitacoes/' + encodeURIComponent(id) + '/status'
        );

        detailBody.replaceChildren();

        el(
            'p',
            (data.statusLabel || data.status || '—') + ' · ' +
            new Date(data.criadaEm).toLocaleString(),
            detailBody,
            'muted'
        );

        const url = requestUrl(data);

        if (url) {
            const link = el('a', 'Abrir no ToolboxWaze ↗', detailBody, 'tw-detail-link');
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
        }

        el('h3', 'Comentários públicos', detailBody);

        if (!data.comentarios?.length) {
            el('p', 'Sem comentários públicos ainda.', detailBody, 'muted');
        } else {
            for (const comment of data.comentarios) {
                const item = el('article', null, detailBody, 'tw-comment');

                el('strong', comment.autor || 'Autor', item);
                el('small', ' · ' + new Date(comment.criadoEm).toLocaleString(), item);
                el('p', comment.texto, item);
            }
        }
    } catch (error) {
        detailBody.replaceChildren(el('p', error.message, null, 'error-text'));
        authLost(error);
    }
}

  async function renderMine() {
    mineList.replaceChildren(el('p', 'Carregando…', null, 'muted'));

    try {
      const data = await api('GET', '/solicitacoes');
      mineList.replaceChildren();

      if (!data.solicitacoes?.length) {
        el('p', 'Você ainda não enviou solicitações.', mineList, 'muted');
        return;
      }

      const table = el('table', null, mineList, 'table');
      const caption = el('caption', null, table);
      caption.textContent = 'Minhas solicitações · ' + data.solicitacoes.length + ' na lista';
      const thead = el('thead', null, table);
      const headRow = el('tr', null, thead);

      ['ID', 'Tipo de solicitação', 'Status', 'Data', 'Ações'].forEach(label => {
        el('th', label, headRow).scope = 'col';
      });

      const tbody = el('tbody', null, table);

      for (const item of data.solicitacoes) {
        const url = requestUrl(item);
        const row = el('tr', null, tbody);

        el('td', '#' + item.id, row);
        el('td', item.formulario || item.tipoLabel || 'Solicitação', row);

        const statusCell = el('td', null, row);
        const statusText = item.statusLabel || item.status || '';
        const statusKey = String(statusText).toLowerCase();
        const statusClass = statusKey.includes('conclu') || statusKey.includes('resolvid')
          ? 'done'
          : statusKey.includes('recus') || statusKey.includes('rejeit')
            ? 'rejected'
            : statusKey.includes('pend') || statusKey.includes('aguard')
              ? 'pending'
              : 'open';

        el('span', statusText || '—', statusCell, 'mine-status ' + statusClass);

        const date = new Date(item.criadaEm);
        el('td', Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString(), row);

        const actions = el('td', null, row, 'actions');
        const details = el('button', 'Detalhes', actions);
        details.onclick = () => showStatus(item.id);

        if (url) {
          const link = el('a', 'Abrir', actions);
          link.href = url;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          link.title = 'Abrir solicitação #' + item.id + ' no ToolboxWaze';
        }
        const columns = ['ID', 'Tipo de solicitação', 'Status', 'Data', 'Ações'];
        Array.from(row.children).forEach((cell,index) => { cell.dataset.label = columns[index]; });
        details.setAttribute('aria-label', 'Ver detalhes da solicitação #' + item.id);
      }
    } catch (error) {
      mineList.replaceChildren(el('p', error.message, null, 'error-text'));
      authLost(error);
    }
  }

  async function loadMine() {
    mineCard.hidden = !mineCard.hidden;

    if (mineCard.hidden) return;

    await renderMine();
  }

  function saveNow() {
    if (!identity || !definition) return Promise.resolve();

    clearTimeout(saveTimer);

    return store.set('tw3_draft_' + String(identity.id) + '_' + definition.slug, {
      answers: structuredClone(answers),
      context,
      updatedAt: Date.now()
    });
  }

  async function result(data) {
    if (!data.solicitacao?.id) {
      throw Object.assign(new Error('Resposta sem identificador de solicitação.'), { uncertain: true });
    }

    applySubmissionCooldown(data);
    unresolved = null;
    await store.del('tw3_pending');

    notice('Solicitação #' + data.solicitacao.id + ' criada com sucesso.', 'success');

    if (data.solicitacao.url) {
      try {
        const url = new URL(data.solicitacao.url, BASE);

        if (url.origin === BASE) {
          const link = el('a', ' Abrir no ToolboxWaze', status);
          link.href = url.href;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
        }
      } catch {}
    }

    if (definition) {
      await store.del(await draftKey());
    }

    clearTimeout(saveTimer);
    answers = {};
    files = {};
    dirty = false;
    renderFields();
    draftNote.textContent = 'Enviado. Anexos e rascunho removidos.';
  }

  async function send() {
    if (cooldownRemaining() > 0 || busy || !identity || !definition || !definition.campos?.length || unresolved) return;

    renderFields();

    const errors = validate();

    if (Object.keys(errors).length) {
      for (const [key, message] of Object.entries(errors)) {
        fieldError(key, message);
      }

      notice('Revise os campos destacados.', 'error');
      fieldArea.querySelector('.bad')?.focus();
      return;
    }

    const segmentId = context?.objetos?.find(object => object.type === 'segment')?.id;

    if (segmentId) {
      busy = true;
      updateBusy();
      notice('Verificando duplicatas…');

      let duplicate = null;

      try {
        duplicate = await api('POST', '/duplicatas', {
          formulario: definition.slug,
          segmentoId: String(segmentId)
        });
      } catch (error) {
        notice('Não foi possível verificar duplicatas: ' + error.message, 'error');
        busy = false;
        updateBusy();
        return;
      }

      busy = false;
      updateBusy();

      if (duplicate?.duplicada) {
        const url = duplicate.solicitacao?.url ? requestUrl(duplicate.solicitacao) : null;

        notice('Já existe uma solicitação recente para este segmento.' + (url ? ' Abra: ' + url : ''), 'error');
        return;
      }
    }

    const key = uid();

    const payload = {
      formulario: definition.slug,
      versaoFormulario: definition.versao,
      respostas: {},
      contexto: context,
      origem: 'wme-userscript',
      scriptVersion: VERSION,
      clientRequestId: key
    };

    for (const field of definition.campos) {
      if (visible(field) && field.tipo !== 'file' && answers[field.chave] !== undefined) {
        payload.respostas[field.chave] = field.tipo === 'number' && answers[field.chave] !== ''
          ? Number(answers[field.chave])
          : answers[field.chave];
      }
    }

    const body = new FormData();
    body.append('payload', JSON.stringify(payload));

    for (const field of definition.campos) {
      if (visible(field) && field.tipo === 'file') {
        for (const file of files[field.chave] || []) {
          body.append('anexos[' + field.chave + '][]', file, file.name);
        }
      }
    }

    busy = true;
    unresolved = {
      key,
      accountId: identity.id,
      createdAt: Date.now(),
      formulario: definition.slug
    };

    try {
      await store.set('tw3_pending', unresolved);
      updateBusy();
      notice('Enviando… Não feche o navegador.');

      const submissionStart = performance.now();
      const data = await api('POST', '/solicitacoes', body);
      await result(data);

      trackEvent('request_created', {
        form_slug: definition?.slug || '',
        has_attachments: Object.values(files).some(list => list.length),
        duration_ms: Math.round(performance.now() - submissionStart)
      });
    } catch (error) {
      trackEvent('request_failed', {
        form_slug: definition?.slug || '',
        http_status: error.status || '',
        error_code: error.codigo || ''
      });
      if (error.status && error.status >= 400 && error.status < 500 && error.status !== 409) {
        unresolved = null;
        await store.del('tw3_pending');
      }

      notice(error.message + (unresolved ? '\nNão repita o envio. Consulte o resultado.' : ''), 'error');

      for (const [key, message] of Object.entries(error.errors || {})) {
        fieldError(key, Array.isArray(message) ? message.join(' ') : String(message));
      }

      if (error.codigo === 'formulario_desatualizado' && definition) {
        const slug = definition.slug;

        busy = false;
        await saveNow();
        await choose(slug);
        notice('O formulário foi atualizado. Revise os campos e envie novamente.', 'error');
      } else {
        authLost(error);
      }
    } finally {
      busy = false;
      updateBusy();
    }
  }

  async function checkPending() {
    if (busy || !unresolved) return;

    if (!identity || String(identity.id) !== String(unresolved.accountId)) {
      notice('Conecte a conta usada no envio pendente.', 'error');
      return;
    }

    busy = true;
    updateBusy();

    try {
      const data = await api('GET', '/envios/' + encodeURIComponent(unresolved.key));

      if (data.estado === 'concluido') {
        await result(data);
      } else if (data.estado === 'nao_criado') {
        unresolved = null;
        await store.del('tw3_pending');
        notice('O servidor confirmou que a solicitação não foi criada. Você pode enviar novamente.');
      } else {
        notice('Envio ainda em processamento. Consulte novamente em instantes.');
      }
    } catch (error) {
      notice('Não foi possível confirmar: ' + error.message, 'error');
    } finally {
      busy = false;
      updateBusy();
    }
  }

  function open() {
    previousFocus = document.activeElement;
    modalOpen = true;
    overlay.hidden = false;

    trackEvent('modal_open', {
      connection_state: conn,
      selection_count: selection().length
    });

    if (!context && sdk) {
      context = collect();
      summary.textContent = summarize();
    }

    prefillNick();

    if (!identity) boot();

    closeButton.focus();
    updateBusy();
  }

  function close() {
    if (busy) {
      notice('Aguarde a operação terminar.');
      return;
    }

    if (dirty) scheduleSave();

    overlay.hidden = true;
    modalOpen = false;
    clearTimeout(cooldownTick);
    cooldownTick = null;
    stopPoll();
    stopAlertPoll();
    previousFocus?.focus();

    trackEvent('modal_closed');
  }

  fab.onclick = open;
  closeButton.onclick = close;
  footerClose.onclick = close;
  accountDisconnect.onclick = () => disconnect(true);
  tabForm.onclick = () => showTab('form');
  tabMine.onclick = () => showTab('mine');
  tabSettings.onclick = () => showTab('settings');

  registerButton.onclick = register;

  verifyButton.onclick = async () => {
    await boot(true);

    if (conn === 'pendente') {
      notice('Ainda aguardando a confirmação do e-mail.');
    }
  };

  resendButton.onclick = resend;
  changeButton.onclick = () => disconnect(false);
  disconnectButton.onclick = () => disconnect(true);
  retryButton.onclick = () => boot();
  analyticsButton.disabled = true;

  refresh.onclick = async () => {
    if (dirty && !(await confirmDialog(
      'Atualizar contexto',
      'Atualizar o contexto do WME? Campos já preenchidos serão preservados.',
      'Atualizar'
    ))) return;

    capture();
  };

  copyButton.onclick = async () => {
    try {
      await navigator.clipboard.writeText(JSON.stringify(context, null, 2));
      notice('Contexto copiado.');
    } catch {
      notice('O navegador bloqueou a cópia.', 'error');
    }
  };

  sendButton.onclick = send;
  checkButton.onclick = checkPending;

  clearButton.onclick = async () => {
    if (!definition || !(await confirmDialog(
      'Apagar rascunho',
      'Apagar o rascunho deste formulário?',
      'Apagar'
    ))) return;

    clearTimeout(saveTimer);
    await store.del(await draftKey());

    answers = {};
    files = {};
    dirty = false;

    prefill();
    renderFields();
    draftNote.textContent = 'Rascunho limpo.';
  };

  overlay.addEventListener('click', event => {
    if (event.target === overlay) close();
  });

  function modalKeydown(event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
      return;
    }

    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
      event.preventDefault();

      if (event.isComposing || event.repeat) return;

      const path = event.composedPath();

      if (path.includes(registerCard)) {
        register();
        return;
      }

      if (path.includes(settings)) return;

      if (!sendButton.disabled) send();

      return;
    }

    if (event.key === 'Tab') {
      const nodes = Array.from(dialog.querySelectorAll('button,a,input,select,textarea'))
        .filter(node => !node.disabled && node.getClientRects().length);

      const first = nodes[0];
      const last = nodes.at(-1);
      const active = root.activeElement;

      if (!nodes.length) {
        event.preventDefault();
        return;
      }

      if (event.shiftKey && (active === first || !dialog.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (active === last || !dialog.contains(active))) {
        event.preventDefault();
        first.focus();
      }
    }
  }

  function shieldKeyboard(event) {
    if (overlay.hidden) return;

    if (
      !dialog.contains(root.activeElement) &&
      event.type === 'keydown' &&
      event.key.length === 1 &&
      !event.ctrlKey &&
      !event.metaKey &&
      !event.altKey
    ) {
      closeButton.focus();
    }

    if (event.type === 'keydown') modalKeydown(event);

    event.stopImmediatePropagation();
    event.stopPropagation();
  }

  document.addEventListener('visibilitychange', () => {
    updateBusy();
    if (document.hidden) {
      stopPoll();
      stopAlertPoll();
      return;
    }

    if (modalOpen) {
      if (conn === 'pendente') startPoll();
      if (conn === 'ativo') startAlertPoll();
    }
  });

  for (const type of ['keydown', 'keypress', 'keyup']) {
    window.addEventListener(type, shieldKeyboard, { capture: true });
  }

  PAGE.twDiagnostico = () => {
    const diagnostics = {
      version: VERSION,
      launcher: permalinkLauncher.diagnostic(),
      sdk: Boolean(sdk),
      conn,
      connected: Boolean(identity),
      form: definition?.slug,
      selected: selection().length,
      pending: Boolean(unresolved),
      modalOpen: !overlay.hidden
    };

    log(diagnostics);

    return diagnostics;
  };

  PAGE.twTestarApi = async () => {
    try {
      const data = await api('GET', '/bootstrap');
      log('API respondeu JSON:', data);

      return data;
    } catch (error) {
      warn('API falhou:', error.status || '', error.codigo || '', error.message);

      return error.message;
    }
  };

  log('Interface carregada', VERSION);
  showTab('form');
  updateAnalyticsButton();
  trackEvent('script_initialized', {
    connection_state: conn,
    sdk_ready: Boolean(sdk)
  });

  try {
    for (let attempt = 0; attempt < 200; attempt++) {
      if (PAGE.SDK_INITIALIZED && typeof PAGE.getWmeSdk === 'function') break;

      if (attempt === 199) {
        throw new Error('SDK indisponível após 60 segundos.');
      }

      await new Promise(resolve => setTimeout(resolve, 300));
    }

    await PAGE.SDK_INITIALIZED;

    sdk = PAGE.getWmeSdk({
      scriptId: 'toolboxwaze-solicitacoes',
      scriptName: 'Comunidade BR — Solicitações'
    });

    if (!sdk.State.isReady()) {
      await sdk.Events.once({ eventName: 'wme-ready' });
    }

    context = collect();
    summary.textContent = summarize();
    prefillNick();

    sdk.Events.on({
      eventName: 'wme-selection-changed',
      eventHandler: () => {
        const count = selection().length;

        fabLabel.textContent = 'Formulário de solicitações BR' + (count ? ' (' + count + ')' : '');

        if (!overlay.hidden) {
          contextNotice.textContent = 'A seleção pode ter mudado. Use Atualizar para recapturar.';
        }
      }
    });

    permalinkLauncher.refresh();
    log('SDK pronto');
  } catch (error) {
    summary.textContent = 'SDK indisponível. Preenchimento manual disponível.';
    warn(error.message);
  }
})();
