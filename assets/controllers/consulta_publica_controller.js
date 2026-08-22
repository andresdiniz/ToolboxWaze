import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['type', 'state', 'city', 'query', 'results', 'list', 'pagination', 'source', 'resultTitle', 'helper', 'map', 'mapButton'];

    connect() {
        this.type = 'radar';
        this.page = 1;
        this.abortController = null;
    }

    selectType(event) {
        this.type = event.currentTarget.dataset.type;
        this.typeTargets.forEach((button) => button.classList.toggle('is-active', button === event.currentTarget));
        this.resetResults();
        if (this.stateTarget.value) {
            this.loadCities();
        }
    }

    async loadCities() {
        const uf = this.stateTarget.value;
        this.cityTarget.innerHTML = '<option value="">Carregando municípios...</option>';
        this.cityTarget.disabled = true;
        if (!uf) {
            this.cityTarget.innerHTML = '<option value="">Primeiro escolha o estado</option>';
            return;
        }
        const url = new URL(this.element.dataset.municipiosUrl, window.location.origin);
        url.searchParams.set('tipo', this.type);
        url.searchParams.set('uf', uf);
        try {
            const response = await fetch(url);
            const data = await response.json();
            this.cityTarget.innerHTML = '<option value="">Selecione o município</option>' + data.items.map((city) => `<option value="${this.escape(city)}">${this.escape(city)}</option>`).join('');
            this.cityTarget.disabled = false;
            this.helperTarget.textContent = 'Agora selecione o município e consulte os resultados.';
        } catch {
            this.cityTarget.innerHTML = '<option value="">Não foi possível carregar</option>';
            this.helperTarget.textContent = 'Tente novamente em instantes.';
        }
    }

    cityChanged() { this.resetResults(); }

    async search() {
        const uf = this.stateTarget.value;
        const city = this.cityTarget.value;
        if (!uf || !city) {
            this.helperTarget.textContent = 'Escolha o estado e o município antes de consultar.';
            return;
        }
        this.page = 1;
        await this.fetchResults();
    }

    async fetchResults() {
        this.abortController?.abort();
        this.abortController = new AbortController();
        const base = this.element.dataset.searchBaseUrl;
        const url = new URL(`${base}/${this.type}`, window.location.origin);
        url.searchParams.set('uf', this.stateTarget.value);
        url.searchParams.set('municipio', this.cityTarget.value);
        url.searchParams.set('q', this.queryTarget.value.trim());
        url.searchParams.set('page', this.page);
        this.resultsTarget.hidden = false;
        this.listTarget.innerHTML = '<div class="public-result-card">Carregando resultados...</div>';
        try {
            const response = await fetch(url, { signal: this.abortController.signal });
            if (!response.ok) throw new Error('request');
            const data = await response.json();
            this.render(data);
        } catch (error) {
            if (error.name !== 'AbortError') this.listTarget.innerHTML = '<div class="public-result-card">Não foi possível concluir a consulta.</div>';
        }
    }

    render(data) {
        this.resultTitleTarget.textContent = `${data.total} resultado${data.total === 1 ? '' : 's'}`;
        this.listTarget.innerHTML = data.items.length ? data.items.map((item) => this.card(item)).join('') : '<div class="public-result-card"><h3>Nenhum resultado encontrado</h3><p>Tente alterar os filtros da consulta.</p></div>';
        this.sourceTarget.textContent = `Fonte: ${data.source.name}. Última atualização disponível: ${data.source.updatedAt ?? 'não informada pela fonte'}.`; 
        this.renderPagination(data);
        this.mapButtonTarget.hidden = !data.items.some((item) => item.latitude && item.longitude);
    }

    card(item) {
        const title = this.escape(item.nome || item.tipo || 'Registro público');
        const address = [item.endereco, item.municipio, item.uf].filter(Boolean).map(this.escape).join(', ');
        const details = [item.telefone, item.velocidade ? `${item.velocidade} km/h` : null, item.sentido].filter(Boolean).map(this.escape).join(' · ');
        return `<article class="public-result-card"><h3>${title}</h3>${address ? `<p>${address}</p>` : ''}${details ? `<p>${details}</p>` : ''}</article>`;
    }

    renderPagination(data) {
        const pages = Math.ceil(data.total / data.limit);
        if (pages <= 1) { this.paginationTarget.innerHTML = ''; return; }
        this.paginationTarget.innerHTML = Array.from({ length: pages }, (_, index) => `<button type="button" data-page="${index + 1}">${index + 1}</button>`).join('');
        this.paginationTarget.querySelectorAll('button').forEach((button) => button.addEventListener('click', () => { this.page = Number(button.dataset.page); this.fetchResults(); }));
    }

    toggleMap() { this.mapTarget.hidden = !this.mapTarget.hidden; }
    resetResults() { this.resultsTarget.hidden = true; this.listTarget.innerHTML = ''; this.paginationTarget.innerHTML = ''; }
    escape(value) { return String(value).replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char])); }
}
