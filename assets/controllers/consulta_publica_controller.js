import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['type','state','city','query','results','list','pagination','source','resultTitle','helper'];

    connect() {
        this.type = this.typeTargets.find((button) => button.classList.contains('is-active'))?.dataset.type || 'radar';
        this.page = 1;
        this.abortController = null;
        this.cityAbortController = null;
        this.element.querySelector('[data-share-results]')?.addEventListener('click', () => this.shareAll());
        this.listTarget.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-share-item], [data-share-item]');
            if (!button || !this.listTarget.contains(button)) return;
            event.preventDefault();
            event.stopPropagation();
            this.shareItem(button);
        });
    }

    selectType(event) { this.type = event.currentTarget.dataset.type || 'radar'; this.typeTargets.forEach((button) => { const active = button === event.currentTarget; button.classList.toggle('is-active', active); button.setAttribute('aria-selected', active ? 'true' : 'false'); }); this.page = 1; this.resetResults(); this.resetCity('Selecione o estado para carregar municípios'); this.updateHelper(`Categoria selecionada: ${this.typeLabel()}. Escolha o estado.`); if (this.stateTarget.value) this.loadCities(); }
    stateChanged() { this.resetResults(); this.loadCities(); }
    cityChanged() { this.resetResults(); this.updateHelper(this.cityTarget.value ? `Município selecionado para ${this.typeLabel()}.` : 'Selecione um município.'); }

    async loadCities() { const uf = this.stateTarget.value; this.cityAbortController?.abort(); this.resetCity(uf ? 'Carregando municípios...' : 'Escolha primeiro o estado'); if (!uf) { this.updateHelper('Selecione uma categoria e um estado para carregar os municípios.'); return; } this.cityAbortController = new AbortController(); const url = new URL(this.element.dataset.municipiosUrl, window.location.origin); url.searchParams.set('tipo', this.type); url.searchParams.set('uf', uf); try { const response = await fetch(url, { signal: this.cityAbortController.signal, headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error(`HTTP ${response.status}`); const payload = await response.json(); const cities = Array.isArray(payload) ? payload : (Array.isArray(payload.items) ? payload.items : []); this.cityTarget.innerHTML = '<option value="">Selecione o município</option>' + cities.map((city) => `<option value="${this.escape(city)}">${this.escape(city)}</option>`).join(''); this.cityTarget.disabled = cities.length === 0; this.updateHelper(cities.length ? `${cities.length} município(s) disponível(is) para ${this.typeLabel()}.` : `Nenhum município com ${this.typeLabel().toLowerCase()} cadastrado neste estado.`); } catch (error) { if (error.name === 'AbortError') return; this.resetCity('Não foi possível carregar os municípios'); this.updateHelper('A API não respondeu. Verifique a rota e tente novamente.'); } }

    async search(event) { event?.preventDefault(); if (!this.stateTarget.value || !this.cityTarget.value) { this.updateHelper('Escolha o estado e o município antes de consultar.'); return; } this.page = 1; await this.fetchResults(); }
    async fetchResults() { this.abortController?.abort(); this.abortController = new AbortController(); const url = new URL(`${this.element.dataset.searchBaseUrl}/${this.type}`, window.location.origin); url.searchParams.set('uf', this.stateTarget.value); url.searchParams.set('municipio', this.cityTarget.value); if (this.queryTarget.value.trim()) url.searchParams.set('q', this.queryTarget.value.trim()); url.searchParams.set('page', this.page); this.resultsTarget.hidden = false; this.listTarget.innerHTML = '<div class="public-result-card public-result-card--loading">Consultando dados oficiais...</div>'; try { const response = await fetch(url, { signal: this.abortController.signal, headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error(`HTTP ${response.status}`); this.render(await response.json()); } catch (error) { if (error.name === 'AbortError') return; this.listTarget.innerHTML = '<div class="public-result-card"><h3>Não foi possível concluir a consulta</h3><p>Verifique a rota da API e tente novamente.</p></div>'; } }

    render(data) { const total = Number(data.total || 0); this.resultTitleTarget.textContent = `${total} resultado${total === 1 ? '' : 's'}`; this.listTarget.innerHTML = (data.items || []).map((item) => this.card(item)).join('') || '<div class="public-result-card"><h3>Nenhum resultado encontrado</h3><p>Tente alterar os filtros.</p></div>'; this.sourceTarget.textContent = `Fonte: ${data.source?.name || 'bases oficiais'}. Última atualização: ${data.source?.updatedAt || 'não informada'}.`; this.renderPagination(data); }

    card(item) {
        const title = item.nome || item.nomeFantasia || item.escola || item.razaoSocial || item.tipo || 'Registro público';
        const fields = Object.entries(item).filter(([key, value]) => !['id','linkWaze'].includes(key) && value !== null && value !== undefined && String(value).trim() !== '').slice(0, 28);
        const summary = fields.slice(0, 5).map(([key, value]) => `${this.humanize(key)}: ${value}`).join(' | ');
        return `<article class="public-result-card" data-record-title="${this.escape(title)}"><div class="public-result-card__head"><div><span class="public-result-card__eyebrow">${this.escape(this.typeLabel())}</span><h3>${this.escape(title)}</h3></div><button type="button" class="public-button public-button--ghost public-share-item" data-share-item="true" data-share-title="${this.escape(title)}" data-share-text="${this.escape(summary)}" aria-label="Compartilhar ${this.escape(title)}">Compartilhar</button></div><div class="public-detail-grid">${fields.map(([key, value]) => `<div class="public-detail-item"><span>${this.escape(this.humanize(key))}</span><strong>${this.escape(value)}</strong></div>`).join('')}</div><p class="public-share-feedback" data-share-feedback aria-live="polite"></p></article>`;
    }

    async shareAll() { await this.share(window.location.href, document.title, `Consulta pública de ${this.typeLabel()}`); }
    async shareItem(button) {
        const card = button.closest('.public-result-card');
        const title = button.dataset.shareTitle || card?.dataset.recordTitle || 'Registro público';
        const text = button.dataset.shareText || title;
        const url = new URL(window.location.href);
        url.searchParams.set('tipo', this.type);
        url.searchParams.set('uf', this.stateTarget.value);
        url.searchParams.set('municipio', this.cityTarget.value);
        if (this.queryTarget.value.trim()) url.searchParams.set('q', this.queryTarget.value.trim());
        await this.share(url.toString(), title, text, card);
    }

    async share(url, title, text = '', card = null) {
        try {
            if (navigator.share) {
                await navigator.share({ title, text, url });
                this.feedback(card, 'Compartilhamento aberto.');
                return;
            }
            const content = `${text ? `${text}\n` : ''}${url}`;
            await this.copyToClipboard(content);
            this.feedback(card, 'Link copiado para a área de transferência.');
        } catch (error) {
            if (error?.name !== 'AbortError') this.feedback(card, 'Não foi possível compartilhar. Copie o endereço da página.');
        }
    }

    async copyToClipboard(text) {
        if (navigator.clipboard?.writeText) { await navigator.clipboard.writeText(text); return; }
        const input = document.createElement('textarea'); input.value = text; input.style.position = 'fixed'; input.style.opacity = '0'; document.body.appendChild(input); input.select(); const copied = document.execCommand('copy'); input.remove(); if (!copied) throw new Error('Clipboard indisponível');
    }

    feedback(card, text) { const target = card?.querySelector('[data-share-feedback]') || this.helperTarget; if (target) { target.textContent = text; window.setTimeout(() => { if (target.textContent === text) target.textContent = ''; }, 5000); } }
    renderPagination(data) { const pages = Math.ceil(Number(data.total || 0) / Number(data.limit || 20)); this.paginationTarget.innerHTML = pages <= 1 ? '' : Array.from({ length: pages }, (_, index) => `<button type="button" data-page="${index + 1}">${index + 1}</button>`).join(''); this.paginationTarget.querySelectorAll('[data-page]').forEach((button) => button.addEventListener('click', () => { this.page = Number(button.dataset.page); this.fetchResults(); })); }
    resetResults() { this.resultsTarget.hidden = true; this.listTarget.innerHTML = ''; this.paginationTarget.innerHTML = ''; }
    resetCity(label) { this.cityTarget.innerHTML = `<option value="">${this.escape(label)}</option>`; this.cityTarget.disabled = true; }
    updateHelper(text) { if (this.hasHelperTarget) this.helperTarget.textContent = text; }
    typeLabel() { return this.typeTargets.find((button) => button.dataset.type === this.type)?.textContent.trim() || 'categoria'; }
    humanize(value) { return value.replace(/([A-Z])/g, ' $1').replace(/^./, (char) => char.toUpperCase()); }
    escape(value) { return String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char])); }
}
