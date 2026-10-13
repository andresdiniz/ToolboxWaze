/**
 * radar.js — funcionalidades do módulo Radares
 */
(function () {
    'use strict';

    // ── MESCLAGEM ──────────────────────────────────────────────────────────
    function setupMerge() {
        const bar = document.getElementById('mergeBar');
        const countEl = document.getElementById('mergeCount');
        const chips = document.getElementById('mergeChips');
        const btnGo = document.getElementById('btnGoMerge');
        const btnClear = document.getElementById('btnClearMerge');
        const checkAll = document.getElementById('checkAll');
        const mergeUrl = bar?.dataset.mergeUrl || '/radares/mesclar';

        if (!bar) {
            return;
        }

        let selected = {};

        function highlightRow(id, active) {
            const row = document.querySelector(`.radar-row[data-id="${id}"]`);

            if (!row) {
                return;
            }

            row.style.background = active
                ? 'color-mix(in oklab, var(--bs-warning, #ffc107) 12%, transparent)'
                : '';
        }

        function updateBar() {
            const ids = Object.keys(selected);

            countEl.textContent = ids.length;

            chips.innerHTML = ids.map((id) => `
                <span class="badge bg-warning text-dark">
                    ${selected[id]}
                    <button
                        type="button"
                        class="btn-close btn-close-sm ms-1"
                        style="font-size:.55rem;filter:none"
                        aria-label="Remover"
                        data-remove="${id}">
                    </button>
                </span>
            `).join('');

            chips.querySelectorAll('[data-remove]').forEach((button) => {
                button.addEventListener('click', () => {
                    const id = button.dataset.remove;

                    delete selected[id];

                    const checkbox = document.querySelector(
                        `.radar-check[value="${id}"]`,
                    );

                    if (checkbox) {
                        checkbox.checked = false;
                    }

                    highlightRow(id, false);
                    updateBar();
                });
            });

            if (ids.length >= 2) {
                bar.style.display = 'block';
                btnGo.disabled = false;
                return;
            }

            if (ids.length === 1) {
                bar.style.display = 'block';
                btnGo.disabled = true;
                return;
            }

            bar.style.display = 'none';
        }

        document.querySelectorAll('.radar-check').forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                const id = checkbox.value;
                const label = checkbox.closest('.radar-row')?.dataset.label || `#${id}`;

                if (checkbox.checked) {
                    if (Object.keys(selected).length >= 5) {
                        checkbox.checked = false;
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
                document.querySelectorAll('.radar-check').forEach((checkbox) => {
                    if (checkAll.checked) {
                        if (Object.keys(selected).length < 5 && !checkbox.checked) {
                            checkbox.checked = true;

                            const id = checkbox.value;
                            const label = checkbox.closest('.radar-row')?.dataset.label || `#${id}`;

                            selected[id] = label;
                            highlightRow(id, true);
                        }

                        return;
                    }

                    checkbox.checked = false;
                    delete selected[checkbox.value];
                    highlightRow(checkbox.value, false);
                });

                updateBar();
            });
        }

        if (btnGo) {
            btnGo.addEventListener('click', () => {
                const ids = Object.keys(selected);

                if (ids.length < 2) {
                    return;
                }

                const params = ids.map((id) => `ids[]=${encodeURIComponent(id)}`).join('&');

                window.location.href = `${mergeUrl}?${params}`;
            });
        }

        if (btnClear) {
            btnClear.addEventListener('click', () => {
                selected = {};

                document.querySelectorAll('.radar-check').forEach((checkbox) => {
                    checkbox.checked = false;
                });

                if (checkAll) {
                    checkAll.checked = false;
                }

                document.querySelectorAll('.radar-row').forEach((row) => {
                    row.style.background = '';
                });

                updateBar();
            });
        }

        updateBar();
    }

    // ── MAPA LEAFLET / MAPTILER ─────────────────────────────────────────────
    function createMapAlert(mapDiv, message) {
        mapDiv.innerHTML = '';

        const alert = document.createElement('div');

        alert.className = 'alert alert-warning rounded-0 border-0 m-0 h-100 d-flex align-items-center small';
        alert.setAttribute('role', 'alert');
        alert.textContent = message;

        mapDiv.appendChild(alert);
    }

    function isValidCoordinate(lat, lon) {
        return Number.isFinite(lat)
            && Number.isFinite(lon)
            && lat >= -90
            && lat <= 90
            && lon >= -180
            && lon <= 180;
    }

    function createWazePopup(wazeUrl) {
        const wrapper = document.createElement('div');

        wrapper.style.fontSize = '.82rem';

        const title = document.createElement('strong');

        title.style.display = 'block';
        title.style.marginBottom = '.3rem';
        title.textContent = '📍 Radar';

        const link = document.createElement('a');

        link.href = wazeUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.style.color = 'var(--tw)';
        link.style.fontWeight = '600';
        link.textContent = '🗺 Abrir no Editor Waze';

        wrapper.appendChild(title);
        wrapper.appendChild(link);

        return wrapper;
    }

    function initWazeMap() {
        const mapDiv = document.getElementById('waze-map');

        if (!mapDiv || mapDiv.dataset.mapInitialized === '1') {
            return;
        }

        const wazeUrl = mapDiv.dataset.wazeUrl || '';
        const mapTilerKey = mapDiv.dataset.maptilerKey || '';

        if (!wazeUrl) {
            mapDiv.style.display = 'none';
            return;
        }

        const latMatch = wazeUrl.match(/[?&]lat=(-?[\d.]+)/i);
        const lonMatch = wazeUrl.match(/[?&]lon=(-?[\d.]+)/i);

        if (!latMatch || !lonMatch) {
            mapDiv.style.display = 'none';
            return;
        }

        const lat = Number.parseFloat(latMatch[1]);
        const lon = Number.parseFloat(lonMatch[1]);

        if (!isValidCoordinate(lat, lon)) {
            createMapAlert(mapDiv, 'Não foi possível identificar coordenadas válidas no link do Waze.');
            return;
        }

        const actions = document.createElement('div');

        actions.className = 'waze-map-actions';
        actions.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16" style="color:var(--tw);flex-shrink:0">
                <path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/>
            </svg>
            <span style="color:var(--color-text);font-weight:600">Localização no mapa</span>
            <span class="waze-map-coords">${lat.toFixed(5)}, ${lon.toFixed(5)}</span>
        `;

        const osmLink = document.createElement('a');

        osmLink.href = `https://www.openstreetmap.org/?mlat=${encodeURIComponent(lat)}&mlon=${encodeURIComponent(lon)}#map=17/${encodeURIComponent(lat)}/${encodeURIComponent(lon)}`;
        osmLink.target = '_blank';
        osmLink.rel = 'noopener';
        osmLink.textContent = 'OSM ↗';
        osmLink.style.color = 'var(--tw)';
        osmLink.style.fontSize = '.72rem';
        osmLink.style.textDecoration = 'none';
        osmLink.style.marginLeft = '.5rem';

        actions.appendChild(osmLink);
        mapDiv.parentNode.insertBefore(actions, mapDiv);

        if (!mapTilerKey) {
            createMapAlert(
                mapDiv,
                'Mapa indisponível: configure a variável MAPTILER_KEY no ambiente da aplicação.',
            );
            return;
        }

        if (typeof L === 'undefined') {
            createMapAlert(
                mapDiv,
                'Não foi possível carregar a biblioteca de mapas. Atualize a página e tente novamente.',
            );
            return;
        }

        mapDiv.dataset.mapInitialized = '1';

        const map = L.map(mapDiv, {
            center: [lat, lon],
            zoom: 17,
            scrollWheelZoom: false,
        });

        const tileLayer = L.tileLayer(
            `https://api.maptiler.com/maps/streets/{z}/{x}/{y}.png?key=${encodeURIComponent(mapTilerKey)}`,
            {
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors ' +
                    '&copy; <a href="https://www.maptiler.com/copyright/" target="_blank" rel="noopener">MapTiler</a>',
                maxZoom: 19,
                crossOrigin: true,
            },
        );

        let tileErrorHandled = false;

        tileLayer.on('tileerror', () => {
            if (tileErrorHandled) {
                return;
            }

            tileErrorHandled = true;

            console.error(
                'Falha ao carregar os tiles do MapTiler. Verifique a MAPTILER_KEY, os limites do plano e as restrições de domínio.',
            );
        });

        tileLayer.addTo(map);

        L.marker([lat, lon])
            .addTo(map)
            .bindPopup(createWazePopup(wazeUrl))
            .openPopup();

        window.setTimeout(() => {
            map.invalidateSize();
        }, 300);
    }

    // ── PREVIEW DO HAZARD ID ─────────────────────────────────────────────────
    function setupHazardPreview() {
        const input = document.getElementById('waze-link-input');

        if (!input) {
            return;
        }

        const preview = document.getElementById('waze-hazard-preview');
        const hazardEl = document.getElementById('waze-hazard-id');

        function update() {
            const match = input.value.match(/[?&]permanentHazards=(\d+)/i);

            if (match) {
                hazardEl.textContent = match[1];
                preview.classList.remove('d-none');
                return;
            }

            preview.classList.add('d-none');
        }

        input.addEventListener('input', update);
        update();
    }

    // ── ORDENAÇÃO DA TABELA ─────────────────────────────────────────────────
    function setupTableSort() {
        const table = document.getElementById('radarTable');

        if (!table) {
            return;
        }

        const tbody = table.querySelector('tbody');

        table.querySelectorAll('th[data-sortable]').forEach((th) => {
            const colIndex = Number.parseInt(th.dataset.sortable, 10);
            let direction = null;
            const icon = th.querySelector('.sort-icon');

            if (!icon) {
                return;
            }

            th.addEventListener('click', () => {
                table.querySelectorAll('th[data-sortable]').forEach((other) => {
                    if (other === th) {
                        return;
                    }

                    const otherIcon = other.querySelector('.sort-icon');

                    if (otherIcon) {
                        otherIcon.textContent = '↕';
                        otherIcon.classList.add('text-muted');
                    }

                    delete other.dataset.dir;
                });

                direction = direction === null || direction === 'desc'
                    ? 'asc'
                    : 'desc';

                th.dataset.dir = direction;
                icon.textContent = direction === 'asc' ? '↑' : '↓';
                icon.classList.remove('text-muted');

                const rows = Array.from(tbody.querySelectorAll('tr.radar-row'));

                rows.sort((rowA, rowB) => {
                    const cellA = rowA.querySelectorAll('td')[colIndex];
                    const cellB = rowB.querySelectorAll('td')[colIndex];

                    const valueA = (cellA?.dataset.sort ?? '').trim();
                    const valueB = (cellB?.dataset.sort ?? '').trim();

                    if (!valueA && !valueB) {
                        return 0;
                    }

                    if (!valueA) {
                        return 1;
                    }

                    if (!valueB) {
                        return -1;
                    }

                    return direction === 'asc'
                        ? valueA.localeCompare(valueB)
                        : valueB.localeCompare(valueA);
                });

                rows.forEach((row) => {
                    tbody.appendChild(row);
                });
            });
        });
    }

    // ── INICIALIZAÇÃO ────────────────────────────────────────────────────────
    function initPage() {
        if (document.body.dataset.radarJsInit === '1') {
            return;
        }

        document.body.dataset.radarJsInit = '1';

        setupMerge();
        initWazeMap();
        setupHazardPreview();
        setupTableSort();
    }

    document.addEventListener('DOMContentLoaded', initPage);
    document.addEventListener('turbo:load', initPage);
}());