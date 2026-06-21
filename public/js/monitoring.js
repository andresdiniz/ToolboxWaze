/**
 * ToolboxWaze — Monitoring SDK
 * Captura: Web Vitals, erros JS, rejeições de Promise, erros AJAX,
 * navegação SPA-like, cliques, sessão e performance de recursos.
 * Envia em lote via beacon para /monitoring/collect.
 */
(function () {
  'use strict';

  // ── Config ────────────────────────────────────────────────────
  var ENDPOINT   = '/monitoring/collect';
  var BATCH_MS   = 4000;   // flush a cada 4s
  var MAX_QUEUE  = 30;     // flush antecipado se fila encher
  var SESSION_KEY = 'tw_sid';

  // ── Session ID (sem localStorage bloqueado) ───────────────────
  var sessionId = (function () {
    try { var s = sessionStorage.getItem(SESSION_KEY); if (s) return s; } catch (e) {}
    var id = Math.random().toString(36).slice(2) + Date.now().toString(36);
    try { sessionStorage.setItem(SESSION_KEY, id); } catch (e) {}
    return id;
  }());

  // ── Fila de eventos ───────────────────────────────────────────
  var queue = [];
  var timer = null;

  function push(type, data) {
    queue.push({ type: type, page: location.pathname, data: data,
                 session_id: sessionId, ts: Date.now() });
    if (queue.length >= MAX_QUEUE) flush();
    else scheduleFlush();
  }

  function scheduleFlush() {
    if (timer) return;
    timer = setTimeout(flush, BATCH_MS);
  }

  function flush() {
    clearTimeout(timer); timer = null;
    if (!queue.length) return;
    var items = queue.splice(0, queue.length);
    // Envia cada item individualmente via beacon (compatível com Safari)
    items.forEach(function (item) {
      var body = JSON.stringify(item);
      if (navigator.sendBeacon) {
        navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'application/json' }));
      } else {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ENDPOINT, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(body);
      }
    });
  }

  // Flush ao sair da página
  window.addEventListener('pagehide', flush);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flush();
  });

  // ── Web Vitals (via PerformanceObserver) ──────────────────────
  var vitalsData = {};

  function observeMetric(type, callback) {
    try {
      var po = new PerformanceObserver(function (list) {
        list.getEntries().forEach(callback);
      });
      po.observe({ type: type, buffered: true });
    } catch (e) {}
  }

  // LCP
  observeMetric('largest-contentful-paint', function (e) {
    vitalsData.lcp = Math.round(e.startTime);
  });

  // CLS
  var clsValue = 0, clsEntries = [];
  observeMetric('layout-shift', function (e) {
    if (!e.hadRecentInput) {
      clsValue += e.value;
      clsEntries.push(e);
    }
    vitalsData.cls = parseFloat(clsValue.toFixed(4));
  });

  // FID
  observeMetric('first-input', function (e) {
    vitalsData.fid = Math.round(e.processingStart - e.startTime);
  });

  // INP (Interaction to Next Paint)
  var inpMax = 0;
  observeMetric('event', function (e) {
    if (e.duration > inpMax) {
      inpMax = e.duration;
      vitalsData.inp = Math.round(e.duration);
    }
  });

  // TTFB
  window.addEventListener('load', function () {
    var nav = performance.getEntriesByType('navigation')[0];
    if (nav) {
      vitalsData.ttfb       = Math.round(nav.responseStart);
      vitalsData.dom_loaded = Math.round(nav.domContentLoadedEventEnd);
      vitalsData.load_time  = Math.round(nav.loadEventEnd);
    }
    // Envia vitals 3s após load para garantir LCP estabilizado
    setTimeout(function () {
      if (Object.keys(vitalsData).length) {
        push('web_vitals', Object.assign({}, vitalsData));
      }
    }, 3000);
  });

  // ── Erros JavaScript ──────────────────────────────────────────
  window.addEventListener('error', function (e) {
    push('js_error', {
      message : e.message || 'Unknown error',
      filename: e.filename || '',
      line    : e.lineno  || 0,
      col     : e.colno   || 0,
      stack   : e.error && e.error.stack ? String(e.error.stack).slice(0, 800) : '',
    });
  });

  window.addEventListener('unhandledrejection', function (e) {
    push('unhandled_rejection', {
      reason: e.reason ? String(e.reason).slice(0, 800) : 'Unknown rejection',
    });
  });

  // ── Intercepta fetch e XMLHttpRequest ────────────────────────
  // XHR
  var OrigXHR = window.XMLHttpRequest;
  function PatchedXHR() {
    var xhr   = new OrigXHR();
    var _url  = '', _method = '';
    var _open = xhr.open.bind(xhr);
    xhr.open = function (method, url) {
      _method = method; _url = url;
      return _open.apply(xhr, arguments);
    };
    var _t0;
    xhr.addEventListener('loadstart', function () { _t0 = Date.now(); });
    xhr.addEventListener('loadend', function () {
      var dur = Date.now() - (_t0 || Date.now());
      var skip = _url.indexOf(ENDPOINT) !== -1 || _url.indexOf('/busca/ac') !== -1;
      if (skip) return;
      if (xhr.status >= 400 || xhr.status === 0) {
        push('ajax_error', { url: _url, method: _method, status: xhr.status, duration_ms: dur });
      } else if (dur > 2000) {
        push('ajax_slow', { url: _url, method: _method, status: xhr.status, duration_ms: dur });
      }
    });
    return xhr;
  }
  PatchedXHR.prototype = OrigXHR.prototype;
  window.XMLHttpRequest = PatchedXHR;

  // fetch
  var origFetch = window.fetch;
  window.fetch = function (input, init) {
    var url    = typeof input === 'string' ? input : (input && input.url) || '';
    var method = (init && init.method) || 'GET';
    var t0     = Date.now();
    var skip   = url.indexOf(ENDPOINT) !== -1 || url.indexOf('/busca/ac') !== -1;
    return origFetch.apply(this, arguments).then(function (res) {
      if (skip) return res;
      var dur = Date.now() - t0;
      if (!res.ok) push('ajax_error', { url: url, method: method, status: res.status, duration_ms: dur });
      else if (dur > 2000) push('ajax_slow', { url: url, method: method, status: res.status, duration_ms: dur });
      return res;
    }).catch(function (err) {
      if (!skip) push('ajax_error', { url: url, method: method, status: 0, error: String(err).slice(0, 300) });
      throw err;
    });
  };

  // ── Tracking de cliques em links ──────────────────────────────
  document.addEventListener('click', function (e) {
    var el = e.target.closest('a[href], button[data-track]');
    if (!el) return;
    var label = el.textContent.trim().slice(0, 80) || el.getAttribute('aria-label') || '';
    push('click', {
      tag   : el.tagName.toLowerCase(),
      label : label,
      href  : el.href || '',
    });
  }, { passive: true, capture: true });

  // ── Recursos lentos (imagens, scripts, CSS) ───────────────────
  window.addEventListener('load', function () {
    var slow = [];
    (performance.getEntriesByType('resource') || []).forEach(function (r) {
      if (r.duration > 1500 && r.initiatorType !== 'beacon') {
        slow.push({ url: r.name.slice(0, 200), type: r.initiatorType, ms: Math.round(r.duration) });
      }
    });
    if (slow.length) push('slow_resource', { resources: slow.slice(0, 20) });
  });

  // ── Integração GTM dataLayer ──────────────────────────────────
  window.dataLayer = window.dataLayer || [];
  var dlPush = window.dataLayer.push.bind(window.dataLayer);
  window.dataLayer.push = function (event) {
    dlPush(event);
    if (event && event.event && typeof event.event === 'string') {
      push('gtm_event', { event: event.event, data: event });
    }
  };

  // ── Expõe flush manual ────────────────────────────────────────
  window.TwMonitoring = { flush: flush, push: push };

}());
