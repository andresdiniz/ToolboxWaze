/**
 * tw-monitor.js — ToolboxWaze Frontend Monitoring
 * Captura: erros JS, promessas rejeitadas, Web Vitals, lentidão AJAX,
 * cliques em links externos, erros de formulário, rage-clicks e performance.
 * Envia em batch para /monitoring/collect a cada 5s ou ao sair da página.
 */
(function () {
  'use strict';

  /* ── Configuração ───────────────────────────────────────── */
  var ENDPOINT   = '/monitoring/collect';
  var BATCH_MS   = 5000;       // flush a cada 5s
  var AJAX_SLOW  = 3000;       // ms — AJAX considerado lento
  var LCP_WARN   = 4000;       // ms — LCP crítico
  var CLS_WARN   = 0.25;       // CLS ruim
  var INP_WARN   = 500;        // ms — INP ruim
  var MAX_QUEUE  = 50;         // máx eventos por flush

  /* ── Estado interno ─────────────────────────────────────── */
  var SESSION_KEY = 'tw_sid';
  var sessionId = (function () {
    try {
      var s = sessionStorage.getItem(SESSION_KEY);
      if (!s) { s = Math.random().toString(36).slice(2) + Date.now().toString(36); sessionStorage.setItem(SESSION_KEY, s); }
      return s;
    } catch (e) { return 'ns_' + Math.random().toString(36).slice(2); }
  }());

  var queue  = [];
  var flushing = false;
  var pageLoadTime = performance.now();
  var perfSent = false;

  /* ── Utilitários ─────────────────────────────────────────── */
  function page() { return location.pathname + location.search; }

  function push(type, data) {
    if (queue.length >= MAX_QUEUE) return;
    queue.push({ type: type, data: data, page: page(), ts: Date.now(), session_id: sessionId });
  }

  function flush() {
    if (flushing || !queue.length) return;
    var batch = queue.splice(0, MAX_QUEUE);
    flushing = true;
    try {
      navigator.sendBeacon(ENDPOINT, JSON.stringify({ batch: batch, session_id: sessionId, page: page() }));
    } catch (e) {
      // fallback fetch keepalive
      try {
        fetch(ENDPOINT, { method: 'POST', body: JSON.stringify({ batch: batch, session_id: sessionId, page: page() }),
          headers: { 'Content-Type': 'application/json' }, keepalive: true });
      } catch (e2) {}
    }
    flushing = false;
  }

  /* ── 1. Erros JavaScript não capturados ──────────────────── */
  window.addEventListener('error', function (e) {
    var isResErr = e.target && e.target !== window && (e.target.tagName === 'SCRIPT' || e.target.tagName === 'LINK' || e.target.tagName === 'IMG');
    if (isResErr) {
      push('resource_error', {
        tag: e.target.tagName,
        src: e.target.src || e.target.href || '',
      });
      return;
    }
    push('js_error', {
      message : e.message || 'unknown',
      filename: e.filename || '',
      line    : e.lineno   || 0,
      col     : e.colno    || 0,
      stack   : e.error && e.error.stack ? String(e.error.stack).slice(0, 800) : '',
    });
    // GTM
    try { window.dataLayer = window.dataLayer || []; window.dataLayer.push({ event: 'js_error', error_message: e.message, error_file: e.filename }); } catch(x){}
  }, true);

  /* ── 2. Promessas rejeitadas ─────────────────────────────── */
  window.addEventListener('unhandledrejection', function (e) {
    var reason = e.reason;
    push('unhandled_rejection', {
      message: reason instanceof Error ? reason.message : String(reason).slice(0, 500),
      stack  : reason instanceof Error && reason.stack ? String(reason.stack).slice(0, 800) : '',
    });
    try { window.dataLayer = window.dataLayer || []; window.dataLayer.push({ event: 'unhandled_rejection', error_message: String(reason).slice(0, 200) }); } catch(x){}
  });

  /* ── 3. Web Vitals (LCP, CLS, INP, TTFB, FCP) ───────────── */
  var vitals = {};
  function reportVital(name, value, rating) {
    vitals[name] = value;
    // Envio imediato de vitals críticos
    if ((name === 'LCP' && value > LCP_WARN) ||
        (name === 'CLS' && value > CLS_WARN) ||
        (name === 'INP' && value > INP_WARN)) {
      push('web_vital_critical', { name: name, value: value, rating: rating });
    }
    try { window.dataLayer = window.dataLayer || []; window.dataLayer.push({ event: 'web_vital', vital_name: name, vital_value: Math.round(value), vital_rating: rating }); } catch(x){}
  }

  // PerformanceObserver — LCP
  try {
    var lcpObs = new PerformanceObserver(function (list) {
      var entries = list.getEntries();
      var last = entries[entries.length - 1];
      if (last) reportVital('LCP', last.startTime, last.startTime > LCP_WARN ? 'poor' : last.startTime > 2500 ? 'needs_improvement' : 'good');
    });
    lcpObs.observe({ type: 'largest-contentful-paint', buffered: true });
  } catch(e) {}

  // CLS
  var clsValue = 0, clsEntries = [];
  try {
    var clsObs = new PerformanceObserver(function (list) {
      list.getEntries().forEach(function (e) {
        if (!e.hadRecentInput) { clsValue += e.value; clsEntries.push(e); }
      });
      reportVital('CLS', clsValue, clsValue > CLS_WARN ? 'poor' : clsValue > 0.1 ? 'needs_improvement' : 'good');
    });
    clsObs.observe({ type: 'layout-shift', buffered: true });
  } catch(e) {}

  // INP
  try {
    var inpObs = new PerformanceObserver(function (list) {
      list.getEntries().forEach(function (e) {
        if (e.duration > (vitals['INP'] || 0)) {
          reportVital('INP', e.duration, e.duration > INP_WARN ? 'poor' : e.duration > 200 ? 'needs_improvement' : 'good');
        }
      });
    });
    inpObs.observe({ type: 'event', durationThreshold: 40, buffered: true });
  } catch(e) {}

  // FCP + TTFB via navigation timing
  window.addEventListener('load', function () {
    setTimeout(function () {
      try {
        var nav = performance.getEntriesByType('navigation')[0];
        if (nav) {
          var ttfb = nav.responseStart - nav.requestStart;
          var domLoad = nav.domContentLoadedEventEnd - nav.fetchStart;
          var fullLoad = nav.loadEventEnd - nav.fetchStart;
          reportVital('TTFB', ttfb, ttfb > 1800 ? 'poor' : ttfb > 800 ? 'needs_improvement' : 'good');
          if (!perfSent) {
            perfSent = true;
            push('page_performance', {
              ttfb       : Math.round(ttfb),
              dom_load   : Math.round(domLoad),
              full_load  : Math.round(fullLoad),
              dom_nodes  : document.querySelectorAll('*').length,
              lcp        : vitals['LCP']  ? Math.round(vitals['LCP'])  : null,
              cls        : vitals['CLS']  ? +vitals['CLS'].toFixed(4) : null,
              inp        : vitals['INP']  ? Math.round(vitals['INP'])  : null,
            });
          }
        }

        // FCP via paint observer
        var paints = performance.getEntriesByType('paint');
        paints.forEach(function (p) {
          if (p.name === 'first-contentful-paint') {
            reportVital('FCP', p.startTime, p.startTime > 3000 ? 'poor' : p.startTime > 1800 ? 'needs_improvement' : 'good');
          }
        });
      } catch (ex) {}
    }, 500);
  });

  /* ── 4. Monkey-patch fetch — detecta erros e lentidão AJAX ── */
  var origFetch = window.fetch;
  window.fetch = function () {
    var args = arguments;
    var url  = typeof args[0] === 'string' ? args[0] : (args[0] && args[0].url) || '';
    // Não monitora o próprio endpoint para evitar loop
    if (url.indexOf(ENDPOINT) !== -1) return origFetch.apply(window, args);
    var t0 = performance.now();
    return origFetch.apply(window, args).then(function (resp) {
      var dur = performance.now() - t0;
      if (!resp.ok) {
        push('ajax_error', { url: url, status: resp.status, duration_ms: Math.round(dur) });
        try { window.dataLayer = window.dataLayer || []; window.dataLayer.push({ event: 'ajax_error', ajax_url: url, ajax_status: resp.status }); } catch(x){}
      } else if (dur > AJAX_SLOW) {
        push('ajax_slow', { url: url, duration_ms: Math.round(dur) });
        try { window.dataLayer = window.dataLayer || []; window.dataLayer.push({ event: 'ajax_slow', ajax_url: url, ajax_duration: Math.round(dur) }); } catch(x){}
      }
      return resp;
    }, function (err) {
      push('ajax_error', { url: url, error: err.message || 'network', duration_ms: Math.round(performance.now() - t0) });
      throw err;
    });
  };

  /* ── 5. Monkey-patch XMLHttpRequest ─────────────────────── */
  var origOpen  = XMLHttpRequest.prototype.open;
  var origSend  = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this._twUrl = url; this._twMethod = method;
    return origOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    var self = this, t0 = performance.now();
    this.addEventListener('loadend', function () {
      if (!self._twUrl || String(self._twUrl).indexOf(ENDPOINT) !== -1) return;
      var dur = performance.now() - t0;
      if (self.status >= 400 || self.status === 0) {
        push('ajax_error', { url: self._twUrl, status: self.status, duration_ms: Math.round(dur) });
      } else if (dur > AJAX_SLOW) {
        push('ajax_slow', { url: self._twUrl, duration_ms: Math.round(dur) });
      }
    });
    return origSend.apply(this, arguments);
  };

  /* ── 6. Rage-click detector (3 cliques < 600ms, < 10px) ──── */
  var rageClicks = [], RAGE_COUNT = 3, RAGE_MS = 600, RAGE_PX = 10;
  document.addEventListener('click', function (e) {
    var now = Date.now();
    rageClicks = rageClicks.filter(function (c) { return now - c.t < RAGE_MS; });
    rageClicks.push({ t: now, x: e.clientX, y: e.clientY });
    if (rageClicks.length >= RAGE_COUNT) {
      var first = rageClicks[0], last = rageClicks[rageClicks.length - 1];
      var dist  = Math.sqrt(Math.pow(last.x - first.x, 2) + Math.pow(last.y - first.y, 2));
      if (dist < RAGE_PX) {
        var el = e.target;
        push('rage_click', {
          selector: getSel(el),
          text    : el.textContent ? el.textContent.trim().slice(0, 100) : '',
          x: e.clientX, y: e.clientY,
        });
        try { window.dataLayer = window.dataLayer || []; window.dataLayer.push({ event: 'rage_click', element: getSel(el) }); } catch(x){}
        rageClicks = [];
      }
    }
  });

  /* ── 7. Links externos ──────────────────────────────────── */
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (href.indexOf('http') === 0 && href.indexOf(location.hostname) === -1) {
      push('outbound_click', { href: href.slice(0, 300), text: a.textContent.trim().slice(0, 100) });
    }
  });

  /* ── 8. Erros de validação de formulário ─────────────────── */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var invalids = form.querySelectorAll(':invalid');
    if (invalids.length) {
      var fields = [];
      invalids.forEach(function (el) {
        fields.push({ name: el.name || el.id, validity: el.validationMessage });
      });
      push('form_validation_error', { form: form.id || form.action || '', fields: fields });
      try { window.dataLayer = window.dataLayer || []; window.dataLayer.push({ event: 'form_validation_error', form_id: form.id, invalid_count: fields.length }); } catch(x){}
    }
  }, true);

  /* ── 9. Scroll depth ─────────────────────────────────────── */
  var scrollMilestones = [25, 50, 75, 100], scrollSent = {};
  window.addEventListener('scroll', (function () {
    var ticking = false;
    return function () {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () {
        ticking = false;
        var h   = document.documentElement.scrollHeight - window.innerHeight;
        if (h <= 0) return;
        var pct = Math.round((window.scrollY / h) * 100);
        scrollMilestones.forEach(function (m) {
          if (pct >= m && !scrollSent[m]) {
            scrollSent[m] = true;
            try { window.dataLayer = window.dataLayer || []; window.dataLayer.push({ event: 'scroll_depth', scroll_pct: m }); } catch(x){}
          }
        });
      });
    };
  }()), { passive: true });

  /* ── 10. Tempo de engajamento ────────────────────────────── */
  var engageStart = Date.now(), totalActive = 0, lastActive = Date.now(), isActive = true;
  ['mousemove','keydown','scroll','click','touchstart'].forEach(function (ev) {
    window.addEventListener(ev, function () { if (!isActive) { isActive = true; lastActive = Date.now(); } }, { passive: true });
  });
  setInterval(function () {
    if (isActive) { totalActive += 5; }
    isActive = false;
  }, 5000);

  /* ── Flush periódico e ao sair ───────────────────────────── */
  setInterval(flush, BATCH_MS);
  window.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });
  window.addEventListener('pagehide', flush);
  window.addEventListener('beforeunload', function () {
    push('session_end', { active_seconds: totalActive, total_seconds: Math.round((Date.now() - engageStart) / 1000) });
    flush();
  });

  /* ── Utilitário: seletor CSS simplificado ────────────────── */
  function getSel(el) {
    if (!el || !el.tagName) return '';
    var s = el.tagName.toLowerCase();
    if (el.id) return s + '#' + el.id;
    if (el.className && typeof el.className === 'string') {
      s += '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.');
    }
    return s.slice(0, 100);
  }

  // Expõe para debug
  window.__twMonitor = { flush: flush, queue: function () { return queue; } };
}());
