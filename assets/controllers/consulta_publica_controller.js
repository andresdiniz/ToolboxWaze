import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['type', 'state', 'city', 'query', 'results', 'list', 'pagination', 'source', 'resultTitle', 'helper', 'map', 'mapButton'];

    connect() {
        this.type = this.typeTargets.find((button) => button.classList.contains('is-active'))?.dataset.type || 'radar';
        this.page = 1;
        this.abortController = null;
        this.cityRequest = null;
    }

    selectType(event) {
        this.type = event.currentTarget.dataset.type;
        this.typeTargets.forEach((button) => {
            const active = button === event.currentTarget;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        this.page = 1;
        this.resetResults();
        this.resetCity('Selecione o estado para carregar municípios');
        if (this.stateTarget.value) {
            this.loadCities();
        }
    }

    async loadCities() {
        const uf = this.stateTarget.value;
        this.cityRequest?.abort();
        this.cityRequest = new AbortController();
        this.resetCity(uf ? 'Carregando municípios com dados...' : 'Primeiro escolha o estado');
        if (!uf) return;

        const url = new URL(this.element.dataset.municipiosUrl, window.location.origin);
        url.searchParams.set('tipo', this.type);
        url.searchParams.set('uf', uf);
        try {
            const response = await fetch(url, { signal: this.cityRequest.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('request');
            const data = await response.json();
            const cities = Array.isArray(data.items) ? data.items : [];
            if (!cities.length) {
                this.resetCity('Nenhum município com dados nesta categoria');
                this.helperTarget.textContent = `Não há ${this.label()} cadastrados para este estado.`;
                return;
            }
            this.cityTarget.innerHTML = '<option value="">Selecione o município</option>' + cities.map((city) => `<option value="${this.escape(city)}">${this.escape(city)}</option>`).join('');
            this.cityTarget.disabled = false;
            this.helperTarget.textContent = `Municípios com dados de ${this.label()} carregados.`;
        } catch (error) {
            if (error.name === 'AbortError') return;
            this.resetCity('Não foi possível carregar os municípios');
            this.helperTarget.textContent = 'Verifique a conexão e tente novamente.';
        }
    }

    stateChanged() {
        this.resetResults();
        this.loadCities();
    }

    cityChanged() { this.resetResults(); }

    async search() {
        if (!this.stateTarget.value || !this.cityTarget.value) {
            this.helperTarget.textContent = 'Escolha o estado e o município antes de consultar.';
            return;
        }
        this.page = 1;
        await this.fetchResults();
    }

    async fetchResults() {
        this.abortController?.abort();
        this.abortController = new AbortController();
        const url = new URL(this.element.dataset.searchBaseUrl, window.location.origin);
        url.searchParams.set('tipo', this.type);
        url.searchParams.set('uf', this.stateTarget.value);
        url.searchParams.set('municipio', this.cityTarget.value);
        if (this.queryTarget.value.trim()) url.searchParams.set('q', this.queryTarget.value.trim());
        url.searchParams.set('page', this.page);
        this.resultsTarget.hidden = false;
        this.listTarget.innerHTML = '<div class="public-result-card">Carregando resultados...</div>';
        try {
            const response = await fetch(url, { signal: this.abortController.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('request');
            this.render(await response.json());
        } catch (error) {
            if (error.name !== 'AbortError') this.listTarget.innerHTML = '<div class="public-result-card"><h3>Não foi possível concluir a consulta</h3><p>Tente novamente em instantes.</p></div>';
        }
    }

    render(data) {
        const total = Number(data.total || 0);
        this.resultTitleTarget.textContent = `${total} resultado${total === 1 ? '' : 's'}`;
        this.listTarget.innerHTML = data.items?.length ? data.items.map((item) => this.card(item)).join('') : '<div class="public-result-card"><h3>Nenhum resultado encontrado</h3><p>Tente alterar os filtros da consulta.</p></div>';
        this.sourceTarget.textContent = `Fonte: ${data.source?.name || 'não informada'}. Última atualização disponível: ${data.source?.updatedAt || 'não informada pela fonte'}.`;
        this.renderPagination(data);
        this.mapButtonTarget.hidden = !(data.items || []).some((item) => item.latitude && item.longitude);
    }

    card(item) {
        const title = this.escape(item.nome || item.tipo || 'Registro público');
        const address = [item.endereco, item.municipio, item.uf].filter(Boolean).map((value) => this.escape(value)).join(', ');
        const details = [item.telefone, item.velocidade ? `${item.velocidade} km/h` : null, item.sentido].filter(Boolean).map((value) => this.escape(value)).join(' · ');
        return `<article class="public-result-card"><h3>${title}</h3>${address ? `<p>${address}</p>` : ''}${details ? `<p>${details}</p>` : ''}</article>`;
    }

    renderPagination(data) {
        const pages = Math.ceil(Number(data.total || 0) / Number(data.limit || 20));
        if (pages <= 1) { this.paginationTarget.innerHTML = ''; return; }
        this.paginationTarget.innerHTML = Array.from({ length: pages }, (_, index) => `<button type="button" data-page="${index + 1}">${index + 1}</button>`).join('');
        this.paginationTarget.querySelectorAll('button').forEach((button) => button.addEventListener('click', () => { this.page = Number(button.dataset.page); this.fetchResults(); }));
    }

    toggleMap() { this.mapTarget.hidden = !this.mapTarget.hidden; }

    resetResults() {
        this.resultsTarget.hidden = true;
        this.listTarget.innerHTML = '';
        this.paginationTarget.innerHTML = '';
    }

    resetCity(message) {
        this.cityTarget.innerHTML = `<option value="">${this.escape(message)}</option>`;
        this.cityTarget.disabled = true;
    }

    label() { return ({ radar: 'radares', escola: 'escolas', posto: 'postos' })[this.type] || 'registros'; }
    escape(value) { return String(value).replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char])); }
}
