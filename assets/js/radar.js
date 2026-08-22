/**
 * radar.js — funcionalidades do módulo Radares
 */
(function() {
    'use strict';

    // ── MESCLAGEM ──────────────────────────────────────────────────────────
    function setupMerge() {
        const bar       = document.getElementById('mergeBar');
        const countEl   = document.getElementById('mergeCount');
        const chips     = document.getElementById('mergeChips');
        const btnGo     = document.getElementById('btnGoMerge');
        const btnClear  = document.getElementById('btnClearMerge');
        const checkAll  = document.getElementById('checkAll');
        const mergeUrl  = bar?.dataset.mergeUrl || '/radares/mesclar';

        if (!bar) return;

        let selected = {};

        function updateBar() {
            const ids = Object.keys(selected);
            countEl.textContent = ids.length;
            chips.innerHTML = ids.map(id =>
                `<span class="badge bg-warning text-dark">${selected[id]}
                    <button type="button" class="btn-close btn-close-sm ms-1"
                            style="font-size:.55rem;filter:none"
                            aria-label="Remover" data-remove="${id}"></button>
                 </span>`
            ).join('');

            chips.querySelectorAll('[data-remove]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.dataset.remove;
                    delete selected[id];
                    const cb = document.querySelector(`.radar-check[value="${id}"]`);
                    if (cb) cb.checked = false;
                    highlightRow(id, false);
                    updateBar();
                });
            });

            if (ids.length >= 2) {
                bar.style.display = 'block';
                btnGo.disabled = false;
            } else if (ids.length === 1) {
                bar.style.display = 'block';
                btnGo.disabled = true;
            } else {
                bar.style.display = 'none';
            }
        }

        function highlightRow(id, on) {
            const row = document.querySelector(`.radar-row[data-id="${id}"]`);
            if (row) {
                row.style.background = on
                    ? 'color-mix(in oklab, var(--bs-warning, #ffc107) 12%, transparent)'
                    : '';
            }
        }

        document.querySelectorAll('.radar-check').forEach(cb => {
            cb.addEventListener('change', () => {
                const id    = cb.value;
                const label = cb.closest('.radar-row')?.dataset.label || ('#' + id);
                if (cb.checked) {
                    if (Object.keys(selected).length >= 5) {
                        cb.checked = false;
                        alert('Selecione no máximo 5 radares por vez.');
                        return;
                    }
                    selected[id] = label;
                    highlightRow(id, true);
                } else {
                    delete selected[id];
                    highlightRow(id, false);
                }
                updateBar();
            });
        });

        if (checkAll) {
            checkAll.addEventListener('change', () => {
                document.querySelectorAll('.radar-check').forEach(cb => {
                    if (checkAll.checked) {
                        if (Object.keys(selected).length < 5 && !cb.checked) {
                            cb.checked = true;
                            const id    = cb.value;
                            const label = cb.closest('.radar-row')?.dataset.label || ('#' + id);
                            selected[id] = label;
                            highlightRow(id, true);
                        }
                    } else {
                        cb.checked = false;
                        delete selected[cb.value];
                        highlightRow(cb.value, false);
                    }
                });
                updateBar();
            });
        }

        if (btnGo) {
            btnGo.addEventListener('click', () => {
                const ids = Object.keys(selected);
                if (ids.length < 2) return;
                const params = ids.map(id => `ids[]=${id}`).join('&');
                window.location.href = `${mergeUrl}?${params}`;
            });
        }

        if (btnClear) {
            btnClear.addEventListener('click', () => {
                selected = {};
                document.querySelectorAll('.radar-check').forEach(cb => { cb.checked = false; });
                if (checkAll) checkAll.checked = false;
                document.querySelectorAll('.radar-row').forEach(r => { r.style.background = ''; });
                updateBar();
            });
        }

        updateBar();
    }

    // ── MAPA LEAFLET (show.html.twig) ─────────────────────────────────────
    function initWazeMap() {
        const mapDiv = document.getElementById('waze-map');
        if (!mapDiv) return;
        // Evita recriar o mapa: se a página vier de um snapshot em cache do
        // Turbo (ex.: usuário navegou pra outra rota e voltou), o mesmo
        // elemento #waze-map já pode ter um Leaflet ativo — chamar L.map()
        // de novo nele gera "Map container is already initialized.".
        if (mapDiv.dataset.mapInitialized === '1') return;

        const wazeUrl = mapDiv.dataset.wazeUrl || '';
        if (!wazeUrl) { mapDiv.style.display = 'none'; return; }

        const latMatch = wazeUrl.match(/[?&]lat=(-?[\d.]+)/);
        const lonMatch = wazeUrl.match(/[?&]lon=(-?[\d.]+)/);
        if (!latMatch || !lonMatch) { mapDiv.style.display = 'none'; return; }
        const lat = parseFloat(latMatch[1]);
        const lon = parseFloat(lonMatch[1]);

        mapDiv.dataset.mapInitialized = '1';

        // Inserir barra de ações
        const actions = document.createElement('div');
        actions.className = 'waze-map-actions';
        actions.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16" style="color:var(--tw);flex-shrink:0"><path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/></svg>
            <span style="color:var(--color-text);font-weight:600">Localização no mapa</span>
            <span class="waze-map-coords">${lat.toFixed(5)}, ${lon.toFixed(5)}</span>
            <a href="https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}&zoom=17" target="_blank" rel="noopener" style="color:var(--tw);font-size:.72rem;text-decoration:none;margin-left:.5rem">OSM ↗</a>
        `;
        mapDiv.parentNode.insertBefore(actions, mapDiv);

        function initLeaflet() {
            if (typeof L === 'undefined') return;
            const map = L.map(mapDiv, { center: [lat, lon], zoom: 17, scrollWheelZoom: false });
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors',
                maxZoom: 19
            }).addTo(map);
            L.marker([lat, lon]).addTo(map)
                .bindPopup(`<div style="font-size:.82rem"><strong style="display:block;margin-bottom:.3rem">📍 Radar</strong>
                    <a href="${wazeUrl}" target="_blank" rel="noopener" style="color:var(--tw);font-weight:600">🗺 Abrir no Editor Waze</a></div>`)
                .openPopup();
            setTimeout(() => { map.invalidateSize(); }, 300);
        }

        if (typeof L !== 'undefined') {
            initLeaflet();
        } else {
            // Carregar Leaflet dinamicamente (só uma vez por página: o
            // guard acima impede reentrância aqui mesmo que initWazeMap()
            // seja chamado de novo antes do script terminar de carregar)
            const existingScript = document.querySelector('script[data-leaflet-loader]');
            if (existingScript) {
                existingScript.addEventListener('load', initLeaflet);
                return;
            }
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(link);
            const script = document.createElement('script');
            script.dataset.leafletLoader = '1';
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = initLeaflet;
            document.head.appendChild(script);
        }
    }

    // ── PREVIEW DO HAZARD ID (show.html.twig) ───────────────────────────
    function setupHazardPreview() {
        const input = document.getElementById('waze-link-input');
        if (!input) return;
        const preview = document.getElementById('waze-hazard-preview');
        const hazardEl = document.getElementById('waze-hazard-id');
        function update() {
            const m = input.value.match(/[?&]permanentHazards=(\d+)/);
            if (m) { hazardEl.textContent = m[1]; preview.classList.remove('d-none'); }
            else   { preview.classList.add('d-none'); }
        }
        input.addEventListener('input', update);
        update();
    }

    // ── ORDENAÇÃO DA TABELA (client-side) ──────────────────────────────
    function setupTableSort() {
        const table = document.getElementById('radarTable');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        table.querySelectorAll('th[data-sortable]').forEach(th => {
            const colIndex = parseInt(th.dataset.sortable, 10);
            let dir = null;
            const icon = th.querySelector('.sort-icon');
            if (!icon) return;
            th.addEventListener('click', () => {
                table.querySelectorAll('th[data-sortable]').forEach(other => {
                    if (other !== th) {
                        other.querySelector('.sort-icon').textContent = '↕';
                        other.querySelector('.sort-icon').classList.add('text-muted');
                        delete other.dataset.dir;
                    }
                });
                dir = (dir === null || dir === 'desc') ? 'asc' : 'desc';
                th.dataset.dir = dir;
                icon.textContent = dir === 'asc' ? '↑' : '↓';
                icon.classList.remove('text-muted');
                const rows = Array.from(tbody.querySelectorAll('tr.radar-row'));
                rows.sort((a, b) => {
                    const tdA = a.querySelectorAll('td')[colIndex];
                    const tdB = b.querySelectorAll('td')[colIndex];
                    const valA = (tdA?.dataset.sort ?? '').trim();
                    const valB = (tdB?.dataset.sort ?? '').trim();
                    if (!valA && !valB) return 0;
                    if (!valA) return 1;
                    if (!valB) return -1;
                    return dir === 'asc' ? valA.localeCompare(valB) : valB.localeCompare(valA);
                });
                rows.forEach(r => tbody.appendChild(r));
            });
        });
    }

    // ── INICIALIZAÇÃO ──────────────────────────────────────────────────
    // DOMContentLoaded cobre o hard-load (F5 / digitar a URL). turbo:load
    // cobre a navegação SPA do Turbo Drive (ex.: clicar num radar na
    // listagem) — sem isso, nada aqui roda ao chegar via link, porque
    // DOMContentLoaded só dispara uma vez por documento.
    function initPage() {
        // No load inicial (F5), tanto DOMContentLoaded quanto turbo:load
        // disparam — sem essa trava, tudo abaixo rodaria 2x (listeners
        // duplicados em checkboxes, ordenação de coluna, etc.). O
        // atributo fica no <body>, que o Turbo substitui inteiro a cada
        // navegação real, então a trava não "vaza" para a próxima página.
        if (document.body.dataset.radarJsInit === '1') return;
        document.body.dataset.radarJsInit = '1';

        setupMerge();
        initWazeMap();
        setupHazardPreview();
        setupTableSort();
    }
    document.addEventListener('DOMContentLoaded', initPage);
    document.addEventListener('turbo:load', initPage);

})();