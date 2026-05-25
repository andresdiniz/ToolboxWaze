/**
 * analytics.js — Módulo centralizado de rastreamento GTM / dataLayer
 *
 * Uso:
 *   import Analytics from './analytics.js';
 *   Analytics.trackSearch('radar SP', 5, ['radar']);
 *
 * Todos os eventos seguem o padrão snake_case para facilitar
 * a configuração de triggers no GTM.
 */

window.dataLayer = window.dataLayer || [];

const Analytics = {
    /**
     * Dispara um evento genérico no dataLayer.
     * @param {string} event  Nome do evento GTM
     * @param {object} data   Dados adicionais
     */
    push(event, data = {}) {
        window.dataLayer.push({ event, ...data });
    },

    // ── Busca global ───────────────────────────────────────────────

    /**
     * Registra uma busca realizada pelo usuário.
     * @param {string}   term         Termo pesquisado
     * @param {number}   resultsCount Número de resultados retornados
     * @param {string[]} tipos        Tipos encontrados, ex: ['radar','posto']
     */
    trackSearch(term, resultsCount, tipos = []) {
        this.push('busca_global', {
            search_term:    term,
            results_count:  resultsCount,
            result_tipos:   tipos.join(','),
        });
    },

    /**
     * Registra o clique em um item do resultado de busca.
     * @param {string} term      Termo que originou a busca
     * @param {string} tipo      Tipo do item clicado: 'radar' | 'posto'
     * @param {string} label     Rótulo exibido do item
     */
    trackSearchClick(term, tipo, label) {
        this.push('busca_click', {
            search_term: term,
            item_tipo:   tipo,
            item_label:  label,
        });
    },

    // ── Exportações ────────────────────────────────────────────────

    /**
     * Registra o download de exportação CSV.
     * @param {'radares'|'postos'} tipo
     */
    trackExport(tipo) {
        this.push('export_csv', { export_tipo: tipo });
    },

    // ── Filtros ────────────────────────────────────────────────────

    /**
     * Registra a aplicação de um filtro em listagens.
     * @param {string} nome   Nome do campo filtrado, ex: 'estado', 'tipo_radar'
     * @param {string} valor  Valor selecionado
     */
    trackFiltro(nome, valor) {
        this.push('filtro_aplicado', {
            filter_name:  nome,
            filter_value: valor,
        });
    },

    // ── Formulários ────────────────────────────────────────────────

    /**
     * Registra o envio de um formulário.
     * @param {string}  formName  Identificador do formulário
     * @param {boolean} success   Se o envio foi bem-sucedido
     */
    trackFormSubmit(formName, success = true) {
        this.push('form_submit', {
            form_name: formName,
            success,
        });
    },

    // ── Tema ───────────────────────────────────────────────────────

    /**
     * Registra a troca de tema claro/escuro.
     * @param {'light'|'dark'} theme  Tema ativado
     */
    trackThemeToggle(theme) {
        this.push('theme_toggle', { theme });
    },

    // ── Erros de página ────────────────────────────────────────────

    /**
     * Registra erros de renderização ou requisição.
     * @param {number|string} code     Código HTTP ou identificador
     * @param {string}        message  Mensagem legível
     */
    trackError(code, message) {
        this.push('page_error', {
            error_code:    code,
            error_message: message,
        });
    },
};

export default Analytics;
