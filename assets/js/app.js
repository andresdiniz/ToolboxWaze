/**
 * app.js — funcionalidades globais do ToolboxWaze
 * Carregado via <script> no base.html.twig
 */

(function() {
    'use strict';

    // ── Tema ───────────────────────────────────────────────────
    const html = document.documentElement;
    const btnTheme = document.querySelector('[data-theme-toggle]');
    const sun = document.getElementById('icon-sun');
    const moon = document.getElementById('icon-moon');

    function applyTheme(t) {
        const style = document.createElement('style');
        style.id = '__theme-no-transition';
        style.textContent = '*, *::before, *::after { transition: none !important; }';
        document.head.appendChild(style);
        html.setAttribute('data-theme', t);
        try { localStorage.setItem('twTheme', t); } catch(e) {}
        if (sun && moon) {
            sun.style.display  = t === 'dark' ? 'inline' : 'none';
            moon.style.display = t === 'dark' ? 'none'  : 'inline';
        }
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({ event: 'theme_toggle', theme: t });
        // Avisa outros scripts (ex: dashboard.js) que o tema mudou,
        // pra quem depende de cor calculada em JS (Chart.js) poder
        // se redesenhar sem precisar de F5.
        document.dispatchEvent(new CustomEvent('tw:themechange', { detail: { theme: t } }));
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                const el = document.getElementById('__theme-no-transition');
                if (el) el.remove();
            });
        });
    }

    let saved = null;
    try { saved = localStorage.getItem('twTheme'); } catch(e) {}
    const current = saved || (window.matchMedia('(prefers-color-scheme:dark)').matches ? 'dark' : 'light');
    applyTheme(current);

    if (btnTheme) {
        btnTheme.addEventListener('click', function() {
            applyTheme(html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        });
    }

    // ── Navbar toggler icon swap ─────────────────────────────
    const navEl = document.getElementById('nav');
    const iconMenu = document.getElementById('nav-icon-menu');
    const iconClose = document.getElementById('nav-icon-close');
    if (navEl && iconMenu && iconClose) {
        navEl.addEventListener('show.bs.collapse', function() {
            iconMenu.style.display = 'none';
            iconClose.style.display = '';
        });
        navEl.addEventListener('hide.bs.collapse', function() {
            iconMenu.style.display = '';
            iconClose.style.display = 'none';
        });
    }

    // ── Auto-dismiss alerts ──────────────────────────────────
    document.querySelectorAll('.alert-animated').forEach(function(el) {
        setTimeout(function() {
            el.classList.add('tw-hiding');
            setTimeout(function() { el.remove(); }, 320);
        }, 4000);
    });

    // ── Reveal on scroll ────────────────────────────────────
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver(function(entries) {
            entries.forEach(function(e) {
                if (e.isIntersecting) {
                    e.target.classList.add('visible');
                    io.unobserve(e.target);
                }
            });
        }, { threshold: 0.1 });
        document.querySelectorAll('.reveal').forEach(function(el) { io.observe(el); });
    } else {
        document.querySelectorAll('.reveal').forEach(function(el) { el.classList.add('visible'); });
    }

    // ── Count-up ──────────────────────────────────────────────
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function easeOutQuart(t) { return 1 - Math.pow(1 - t, 4); }

    function countUp(el) {
        const raw = el.dataset.target;
        if (!raw) return;
        const target = parseFloat(raw);
        if (isNaN(target) || target === 0) {
            el.textContent = Math.round(target).toLocaleString('pt-BR');
            return;
        }
        if (reducedMotion) {
            el.textContent = Math.round(target).toLocaleString('pt-BR');
            return;
        }
        const duration = Math.min(1200, Math.max(600, target / 50));
        let startTime = null;
        function step(ts) {
            if (!startTime) startTime = ts;
            const elapsed = ts - startTime;
            const progress = Math.min(elapsed / duration, 1);
            el.textContent = Math.round(easeOutQuart(progress) * target).toLocaleString('pt-BR');
            if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    const ioCount = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) {
            if (e.isIntersecting) {
                countUp(e.target);
                ioCount.unobserve(e.target);
            }
        });
    }, { threshold: 0.5 });
    document.querySelectorAll('.count-up').forEach(function(el) { ioCount.observe(el); });

    // ── Busca global ─────────────────────────────────────────
    function buildSearch(inputEl, dropdownEl) {
        if (!inputEl || !dropdownEl) return;
        let timer = null;
        let lastQ = '';
        let controller = null;

        function render(items, q) {
            while (dropdownEl.firstChild) dropdownEl.removeChild(dropdownEl.firstChild);
            if (!items.length) {
                const empty = document.createElement('div');
                empty.className = 'search-empty';
                empty.textContent = 'Nenhum resultado encontrado.';
                dropdownEl.appendChild(empty);
            } else {
                items.forEach(function(r) {
                    const a = document.createElement('a');
                    a.className = 'search-item';
                    a.href = r.url;
                    a.setAttribute('tabindex', '0');
                    a.setAttribute('role', 'option');

                    const badge = document.createElement('span');
                    badge.className = 'search-item-type ' + (r.tipo === 'radar' ? 'radar' : (r.tipo === 'escola' ? 'escola' : 'posto'));
                    badge.textContent = r.tipo === 'radar' ? 'Radar' : (r.tipo === 'escola' ? 'Escola' : 'Posto');

                    const label = document.createElement('span');
                    label.className = 'search-item-label';
                    label.textContent = r.label;

                    a.appendChild(badge);
                    a.appendChild(label);
                    if (r.badge) {
                        const sm = document.createElement('small');
                        sm.className = 'text-muted';
                        sm.textContent = r.badge;
                        a.appendChild(sm);
                    }
                    a.addEventListener('click', function() {
                        window.dataLayer = window.dataLayer || [];
                        window.dataLayer.push({
                            event: 'busca_click',
                            search_term: lastQ,
                            item_tipo: r.tipo,
                            item_label: r.label
                        });
                    });
                    dropdownEl.appendChild(a);
                });
            }
            const footer = document.createElement('a');
            footer.className = 'search-footer';
            footer.href = '/busca?q=' + encodeURIComponent(q);
            footer.textContent = 'Ver todos os resultados para "' + q + '" →';
            dropdownEl.appendChild(footer);
            dropdownEl.classList.add('show');
        }

        function search(q) {
            if (controller) { try { controller.abort(); } catch(e) {} }
            controller = new AbortController();
            fetch('/busca/ac?q=' + encodeURIComponent(q), { signal: controller.signal })
                .then(function(r) { return r.json(); })
                .then(function(items) {
                    render(items, q);
                    window.dataLayer = window.dataLayer || [];
                    window.dataLayer.push({
                        event: 'busca_global',
                        search_term: q,
                        results_count: items.length,
                        result_tipos: items.map(function(i) { return i.tipo; }).filter(function(v,i,a) { return a.indexOf(v)===i; }).join(',')
                    });
                })
                .catch(function(err) { if (err.name !== 'AbortError') dropdownEl.classList.remove('show'); });
        }

        inputEl.addEventListener('input', function() {
            const q = this.value.trim();
            clearTimeout(timer);
            if (q.length < 2) { dropdownEl.classList.remove('show'); return; }
            if (q === lastQ) return;
            lastQ = q;
            timer = setTimeout(function() { search(q); }, 280);
        });

        inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { dropdownEl.classList.remove('show'); inputEl.blur(); return; }
            if (e.key === 'Enter') {
                e.preventDefault();
                const q = inputEl.value.trim();
                if (q.length >= 2) { window.location.href = '/busca?q=' + encodeURIComponent(q); }
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                const items = dropdownEl.querySelectorAll('.search-item');
                if (items.length) items[0].focus();
            }
        });

        dropdownEl.addEventListener('keydown', function(e) {
            const items = Array.from(dropdownEl.querySelectorAll('.search-item, .search-footer'));
            const idx = items.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); if (idx < items.length - 1) items[idx + 1].focus(); }
            if (e.key === 'ArrowUp')   { e.preventDefault(); idx > 0 ? items[idx - 1].focus() : inputEl.focus(); }
            if (e.key === 'Escape')    { dropdownEl.classList.remove('show'); inputEl.focus(); }
        });

        document.addEventListener('click', function(e) {
            if (!inputEl.contains(e.target) && !dropdownEl.contains(e.target)) {
                dropdownEl.classList.remove('show');
            }
        });
    }

    buildSearch(
        document.getElementById('globalSearch'),
        document.getElementById('searchDropdown')
    );
    buildSearch(
        document.getElementById('globalSearchMobile'),
        document.getElementById('searchDropdownMobile')
    );

    // ── Fechar searchDropdown ao clicar em nav-link ────────
    document.querySelectorAll('.nav-link').forEach(function(el) {
        el.addEventListener('click', function() {
            const sd = document.getElementById('searchDropdown');
            if (sd) sd.classList.remove('show');
        });
    });

    // ── Reinicializar dropdowns após Turbo ──────────────────
    document.addEventListener('turbo:load', function() {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(el) {
            const existing = bootstrap.Dropdown.getInstance(el);
            if (existing) existing.dispose();
            new bootstrap.Dropdown(el);
        });
    });

    // ── GTM: listener delegado para data-gtm-event ──────────
    document.addEventListener('click', function(e) {
        const el = e.target.closest('[data-gtm-event]');
        if (!el) return;
        const payload = { event: el.getAttribute('data-gtm-event') };
        Array.from(el.attributes).forEach(function(attr) {
            if (attr.name.startsWith('data-gtm-') && attr.name !== 'data-gtm-event') {
                payload[attr.name.replace('data-gtm-', '')] = attr.value;
            }
        });
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push(payload);
    });

    // ── Data-utc formatação de datas ────────────────────────
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    const fmtDateTime = new Intl.DateTimeFormat('pt-BR', { timeZone: tz, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    const fmtDate = new Intl.DateTimeFormat('pt-BR', { timeZone: tz, day: '2-digit', month: '2-digit', year: 'numeric' });
    document.querySelectorAll('[data-utc]').forEach(function(el) {
        let raw = el.getAttribute('data-utc');
        if (!raw) return;
        if (!/Z|[+-]\d{2}:?\d{2}$/.test(raw)) raw = raw.replace(' ', 'T') + 'Z';
        const d = new Date(raw);
        if (isNaN(d.getTime())) return;
        const hasTime = /T|\d{2}:\d{2}/.test(el.getAttribute('data-utc'));
        el.setAttribute('title', 'UTC: ' + el.getAttribute('data-utc'));
        el.setAttribute('datetime', el.getAttribute('data-utc'));
        el.textContent = hasTime ? fmtDateTime.format(d) : fmtDate.format(d);
    });

})();