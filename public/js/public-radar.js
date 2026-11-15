(() => {
    'use strict';
    const init = () => {
        const feedback = (text) => {
            const target = document.querySelector('[data-feedback]');
            if (target) target.textContent = text;
        };
        const pageUrl = () => document.querySelector('link[rel="canonical"]')?.href || location.href;
        const copy = async () => {
            const url = pageUrl();
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(url);
                } else {
                    const input = document.createElement('textarea');
                    input.value = url;
                    input.setAttribute('readonly', '');
                    input.style.position = 'fixed';
                    input.style.left = '-9999px';
                    document.body.append(input);
                    input.select();
                    const ok = document.execCommand('copy');
                    input.remove();
                    if (!ok) throw new Error('Clipboard unavailable');
                }
                feedback('Link copiado.');
            } catch {
                feedback(`Não foi possível copiar automaticamente. Link: ${url}`);
            }
        };
        document.querySelectorAll('[data-share]').forEach(button => button.addEventListener('click', async () => {
            if (!navigator.share) return copy();
            try {
                await navigator.share({ title: document.title, url: pageUrl() });
            } catch (error) {
                if (error.name !== 'AbortError') await copy();
            }
        }));
        document.querySelectorAll('[data-copy-link]').forEach(button => button.addEventListener('click', copy));
        const form = document.querySelector('[data-radar-form]');
        if (form) {
            const uf = form.elements.namedItem('uf');
            const city = form.elements.namedItem('municipio');
            const cities = document.getElementById('radar-cities');
            let pending;
            uf.addEventListener('change', async () => {
                pending?.abort();
                city.value = '';
                cities.replaceChildren();
                if (!uf.value) return;
                const controller = new AbortController();
                pending = controller;
                const url = new URL('/consultar/api/municipios', location.origin);
                url.searchParams.set('tipo', 'radar');
                url.searchParams.set('uf', uf.value);
                try {
                    const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
                    if (!response.ok) throw new Error('Municipalities unavailable');
                    const data = await response.json();
                    if (pending !== controller || !Array.isArray(data.items)) return;
                    for (const name of data.items) {
                        if (typeof name !== 'string') continue;
                        const option = document.createElement('option');
                        option.value = name;
                        cities.append(option);
                    }
                    feedback('Municípios disponíveis nas sugestões. Você também pode digitar o nome completo.');
                } catch (error) {
                    if (error.name !== 'AbortError') feedback('Sugestões indisponíveis. Digite o município para pesquisar.');
                }
            });
            form.addEventListener('submit', event => {
                const start = form.elements.namedItem('validade_de');
                const end = form.elements.namedItem('validade_ate');
                end.setCustomValidity('');
                if (start.value && end.value && start.value > end.value) {
                    event.preventDefault();
                    end.setCustomValidity('A data final deve ser igual ou posterior à inicial.');
                    end.reportValidity();
                }
            });
            form.elements.namedItem('validade_ate').addEventListener('input', event => event.target.setCustomValidity(''));
            form.elements.namedItem('validade_de').addEventListener('input', () => form.elements.namedItem('validade_ate').setCustomValidity(''));
        }
        const load = document.querySelector('[data-load-map]');
        if (load) load.addEventListener('click', async () => {
            const target = document.querySelector('[data-radar-map]');
            const lat = Number(target.dataset.lat);
            const lon = Number(target.dataset.lon);
            if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) return;
            load.disabled = true;
            load.textContent = 'Carregando mapa…';
            try {
                if (!window.L) {
                    const css = document.createElement('link');
                    css.rel = 'stylesheet';
                    css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    const cssReady = new Promise((resolve, reject) => { css.onload = resolve; css.onerror = reject; });
                    document.head.append(css);
                    const js = document.createElement('script');
                    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    const jsReady = new Promise((resolve, reject) => { js.onload = resolve; js.onerror = reject; });
                    document.head.append(js);
                    await Promise.all([cssReady, jsReady]);
                }
                target.hidden = false;
                const map = window.L.map(target, { scrollWheelZoom: false }).setView([lat, lon], 16);
                window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                const label = document.createElement('span');
                label.textContent = 'Localização de referência · Editores Waze Brasil';
                window.L.marker([lat, lon]).addTo(map).bindPopup(label);
                load.hidden = true;
                requestAnimationFrame(() => map.invalidateSize());
            } catch {
                target.hidden = true;
                load.disabled = false;
                load.textContent = 'Não foi possível carregar. Tentar novamente';
            }
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
