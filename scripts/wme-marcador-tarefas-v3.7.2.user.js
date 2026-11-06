// ==UserScript==
// @name         WME Tarefas Pendentes
// @version      3.7.2
// @description  Marcador de Tarefas (lombadas, semáforos, faixas, escolas, saúde...) com WME SDK, token pessoal validado, ranking, filtros de Saúde (tipo e município), seleção automática do Place, comparação fonte × WME e fluxo de confirmação simplificado
// @author       Andre Soares Diniz
// @match        https://www.waze.com/editor*
// @match        https://www.waze.com/*/editor*
// @match        https://beta.waze.com/editor*
// @match        https://beta.waze.com/*/editor*
// @exclude      https://www.waze.com/user/editor*
// @grant        GM_xmlhttpRequest
// @grant        GM_info
// @grant        GM_getValue
// @grant        GM_setValue
// @grant        GM_deleteValue
// @grant        unsafeWindow
// @connect      script.google.com
// @connect      script.googleusercontent.com
// @connect      wazetoolbox.acheireviews.com.br
// ==/UserScript==

/* global GM_info, GM_xmlhttpRequest, GM_getValue, GM_setValue, GM_deleteValue, unsafeWindow */

(function () {
    'use strict';

    const SCRIPT_ID = 'wme-marcador-tarefas';
    const SCRIPT_NAME = 'WME Marcador de Tarefas';
    const LAYER_NAME = 'wme_marcador_tarefas';
    const LOG = '📍 WME Marcador:';

    const URLS_API = {
        RANKING: 'https://script.google.com/macros/s/AKfycbyQV4Jqi2T3Oy61y7niE4JwZB0Y7auFE6QSBdaIXdytVVCIBM2z-hk_QMbcQqJ7sApU0g/exec',
        LOMBADAS: 'https://script.google.com/macros/s/AKfycbxAbYBimPOLsx6TTXVuQeSxilBI7X48AVzUID3Xvq2ROes88cyS6Em8Du5-HbXIQacC/exec',
        SEMAFOROS: 'https://script.google.com/macros/s/AKfycbx6TjgppGT2TeiV6egPG2x58P9CFgv9p4LM3tjUfpWUHE1Q7h8YQuu8x-5OTVEEzZuW/exec',
        FAIXA_ELEVADA: 'https://script.google.com/macros/s/AKfycbyszzCgTZCC9qUcyOBql59ktjfoseI-ixxlJzfaRBHD5e3bO2eQt-j6mLEjtZrVzQlK6A/exec',
        ESTREITAMENTO: 'https://script.google.com/macros/s/AKfycbyNo5pJ-Q_vkn95FVj0_U0DIuFVxb8nVgjwGLNEGBytI2pB4Al3cG683ZwLsRdOW1vWmA/exec',
        CONFLUENCIA: 'https://script.google.com/macros/s/AKfycbx82hpaeRjolTgNIG9AZhkI-W0QFwuNRLFiwfvr3Jc-6ymWL-KqUTB4KdycsYxAO1x5/exec'
    };

    const TOOLBOX_API = {
        BASE: 'https://wazetoolbox.acheireviews.com.br',
        TOKEN_PAGE: 'https://wazetoolbox.acheireviews.com.br/profile/api-token',
        LOTE: 50
    };

    const GREASYFORK_URL = 'https://greasyfork.org/pt-BR/scripts/587740-wme-marcador-de-tarefas';

    const KEYS = {
        TOKEN: 'toolbox_api_token',
        CACHE: 'tarefas_reservadas',
        USUARIO: 'usuario',
        TIPO: 'ultimo_tipo',
        UF: 'ultima_uf',
        FILTROS: 'filtros_saude'
    };

    const LEGACY_LS = {
        TOKEN: 'wme_marcador_escolas_token',
        CACHE: 'wme_marcador_escolas_reservadas'
    };

    const INATIVIDADE_LIMITE_MS = 30 * 60 * 1000;
    const INATIVIDADE_CHECK_MS = 60 * 1000;
    const RANKING_REFRESH_MS = 20000;

    // Leitura do place (venue) no modelo de dados do WME após centralizar o mapa.
    const VENUE = {
        ESPERA_MS: 15000,
        INTERVALO_MS: 300,
        NENHUM: '__nenhum__'
    };

    // Filtros da Saúde (enviados à API em /reservar e /filtros).
    const FILTROS_PADRAO = {
        tipo: '',
        municipio: ''
    };

    const FILTRO_IDS = {
        tipo: 'wmt-f-tipo',
        municipio: 'wmt-f-municipio'
    };

    const UFS_BRASIL = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
        'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
        'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'
    ];

    const TIPOS = {
        LOMBADAS: {
            rotulo: 'Lombadas',
            url: URLS_API.LOMBADAS
        },
        SEMAFOROS: {
            rotulo: 'Semáforos',
            url: URLS_API.SEMAFOROS
        },
        FAIXA_ELEVADA: {
            rotulo: 'Faixa Elevada',
            url: URLS_API.FAIXA_ELEVADA
        },
        ESTREITAMENTO: {
            rotulo: 'Estreitamento',
            url: URLS_API.ESTREITAMENTO,
            avisoPrecisao: true
        },
        CONFLUENCIA: {
            rotulo: 'Confluência',
            url: URLS_API.CONFLUENCIA,
            avisoPrecisao: true
        },
        ESCOLAS: {
            rotulo: 'Escolas',
            api: 'escolas'
        },
        SAUDE: {
            rotulo: 'Saúde',
            api: 'saude'
        }
    };

    const RANK_TIPOS = [
        { chave: 'lombadas', rotulo: 'Lombadas', emoji: '🚧', cor: '#4CAF50' },
        { chave: 'semaforos', rotulo: 'Semáforos', emoji: '🚦', cor: '#FF9800' },
        { chave: 'faixaElevada', rotulo: 'Faixa Elevada', emoji: '🚶', cor: '#2196F3' },
        { chave: 'estreitamento', rotulo: 'Estreitamento', emoji: '↔️', cor: '#9C27B0' },
        { chave: 'confluencia', rotulo: 'Confluência', emoji: '🔀', cor: '#009688' }
    ];

    const RANK_TOP_FIXOS = ['lombadas', 'semaforos', 'faixaElevada'];
    const MEDALHAS = ['🥇', '🥈', '🥉', '4º', '5º'];

    const MARKER_ICON = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32">' +
        '<path d="M16 1C9.4 1 4 6.2 4 12.7 4 21.5 16 31 16 31s12-9.5 12-18.3C28 6.2 22.6 1 16 1z" fill="#e53935" stroke="#fff" stroke-width="2"/>' +
        '<circle cx="16" cy="12.5" r="4.5" fill="#fff"/></svg>'
    );

    const ETAPA_ICONE = {
        pendente: '○',
        andamento: '◔',
        ok: '✓',
        erro: '✕',
        pulada: '–'
    };

    const IDS_CONTROLES_ENVIO = [
        'wmt-uf',
        'wmt-tipo',
        'wmt-usuario',
        'wmt-btn-limpar',
        'wmt-btn-token',
        'wmt-btn-remover-token',
        'wmt-f-tipo',
        'wmt-f-municipio',
        'wmt-f-limpar'
    ];

    const state = {
        sdk: null,
        painel: null,
        lote: [],
        indice: 0,
        tipoLote: null,
        ufLote: null,
        carregando: false,
        salvando: false,
        loadSeq: 0,
        venueSeq: 0,
        venueEventsBound: false,
        venueRefreshTimer: null,
        selecaoTimer: null,
        facetas: { tipos: [], municipios: [], disponiveis: null },
        facetaSeq: 0,
        ultimoResultado: null,
        abaAtiva: 'atividade',
        rankingTimer: null,
        rankingBuscando: false,
        ultimaAtividade: Date.now(),
        inatividadeTimer: null,
        etapas: null,
        iniciado: false
    };

    function esc(valor) {
        return String(valor === null || valor === undefined ? '' : valor)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function urlSegura(url) {
        return /^https?:\/\//i.test(String(url || '')) ? String(url) : '';
    }

    function compararVersoes(a, b) {
        const pa = String(a).split('.').map(n => parseInt(n, 10) || 0);
        const pb = String(b).split('.').map(n => parseInt(n, 10) || 0);
        const tamanho = Math.max(pa.length, pb.length);

        for (let i = 0; i < tamanho; i++) {
            const diferenca = (pa[i] || 0) - (pb[i] || 0);

            if (diferenca !== 0) {
                return diferenca > 0 ? 1 : -1;
            }
        }

        return 0;
    }

    function ui(id) {
        return state.painel
            ? state.painel.querySelector('#' + id)
            : null;
    }

    const store = {
        get(chave, padrao) {
            try {
                const valor = GM_getValue(chave);
                return valor === undefined || valor === null ? padrao : valor;
            } catch (erro) {
                return padrao;
            }
        },
        set(chave, valor) {
            try {
                GM_setValue(chave, valor);
            } catch (erro) {
                console.warn(LOG, 'Falha ao gravar', chave, erro);
            }
        },
        del(chave) {
            try {
                GM_deleteValue(chave);
            } catch (erro) {
                // Ignorado.
            }
        },
        getJson(chave) {
            try {
                const bruto = store.get(chave, null);
                return bruto ? JSON.parse(bruto) : null;
            } catch (erro) {
                return null;
            }
        },
        setJson(chave, valor) {
            store.set(chave, JSON.stringify(valor));
        }
    };

    function migrarArmazenamentoLegado() {
        try {
            const tokenAntigo = localStorage.getItem(LEGACY_LS.TOKEN);

            if (tokenAntigo && !store.get(KEYS.TOKEN, '')) {
                store.set(KEYS.TOKEN, tokenAntigo.trim());
            }

            localStorage.removeItem(LEGACY_LS.TOKEN);
            localStorage.removeItem(LEGACY_LS.CACHE);
        } catch (erro) {
            // localStorage indisponível.
        }
    }

    const obterToken = () => String(store.get(KEYS.TOKEN, '') || '');
    const salvarToken = token => store.set(KEYS.TOKEN, token.trim());
    const limparToken = () => store.del(KEYS.TOKEN);

    const lerCache = () => store.getJson(KEYS.CACHE);
    const limparCache = () => store.del(KEYS.CACHE);

    function salvarCache(dados) {
        store.setJson(KEYS.CACHE, {
            esquema: 5,
            api: dados.api,
            uf: dados.uf,
            username: dados.username,
            filtros: dados.filtros || null,
            itens: dados.itens,
            salvoEm: new Date().toISOString(),
            ultimaAtividadeEm: Date.now()
        });
    }

    function removerDoCache(item) {
        const cache = lerCache();

        if (cache && Array.isArray(cache.itens)) {
            cache.itens = cache.itens.filter(
                registro => String(registro.id) !== String(item.id)
            );

            if (cache.itens.length === 0) {
                limparCache();
            } else {
                salvarCache(cache);
            }
        }
    }

    function descreverErroRede(titulo, url, resposta) {
        let host = url;

        try {
            host = new URL(url).host;
        } catch (erro) {
            // Mantém a URL original.
        }

        const partes = [];

        if (resposta) {
            if (resposta.error) {
                partes.push(String(resposta.error));
            }

            if (resposta.status) {
                partes.push(
                    `HTTP ${resposta.status}${resposta.statusText ? ' ' + resposta.statusText : ''}`
                );
            }

            const corpo = String(resposta.responseText || '')
                .replace(/\s+/g, ' ')
                .trim();

            if (corpo) {
                partes.push(corpo.slice(0, 180));
            }
        }

        if (!navigator.onLine) {
            partes.push('navegador sem internet');
        }

        const detalhe = partes.length
            ? partes.join(' | ')
            : 'sem detalhes (possível bloqueio de @connect no Tampermonkey, CORS, DNS ou servidor fora do ar)';

        console.error(LOG, titulo, '→', url, resposta);

        return new Error(`${titulo} [${host}]: ${detalhe}`);
    }

    function gmRequest({
        method = 'GET',
        url,
        headers = {},
        body,
        timeout = 30000
    }) {
        return new Promise((resolve, reject) => {
            try {
                GM_xmlhttpRequest({
                    method,
                    url,
                    headers,
                    data: body,
                    timeout,
                    onload: resolve,
                    onerror: resposta => reject(
                        descreverErroRede('Falha de conexão', url, resposta)
                    ),
                    ontimeout: resposta => reject(
                        descreverErroRede(
                            `Tempo limite (${Math.round(timeout / 1000)}s)`,
                            url,
                            resposta
                        )
                    ),
                    onabort: resposta => reject(
                        descreverErroRede('Requisição cancelada', url, resposta)
                    )
                });
            } catch (erro) {
                reject(
                    descreverErroRede(
                        'Requisição não iniciada',
                        url,
                        {
                            error: erro && erro.message
                                ? erro.message
                                : String(erro)
                        }
                    )
                );
            }
        });
    }

    function lerJson(resposta, mensagemErro) {
        try {
            return JSON.parse(resposta.responseText);
        } catch (erro) {
            throw new Error(mensagemErro);
        }
    }

    async function chamarAppsScript(url, payload) {
        const resposta = await gmRequest({
            method: 'POST',
            url,
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const dados = lerJson(
            resposta,
            'Erro ao ler a resposta do servidor.'
        );

        if (dados.resultado !== 'sucesso') {
            throw new Error(
                dados.mensagem || 'Erro desconhecido do servidor.'
            );
        }

        return dados;
    }

    async function validarToken(token) {
        const resposta = await gmRequest({
            url: TOOLBOX_API.BASE + '/api/auth/validate',
            headers: {
                'X-API-TOKEN': token,
                'Accept': 'application/json'
            }
        });

        const corpo = String(resposta.responseText || '');
        let dados = null;

        try {
            dados = JSON.parse(corpo);
        } catch (erro) {
            // Tratado abaixo.
        }

        if (resposta.status === 200 && dados && dados.valid === true) {
            return dados;
        }

        const detalhe = dados
            ? (dados.error || `valid=${dados.valid}`)
            : (corpo.slice(0, 120) || 'resposta vazia');

        throw new Error(
            `Token inválido (HTTP ${resposta.status}): ${detalhe}`
        );
    }

    async function chamarApi(metodo, caminho, payload, opcoes = {}) {
        const token = obterToken();

        if (!token) {
            if (!opcoes.silencioso) {
                abrirModalToken();
            }

            throw new Error(
                'Informe o Token da API antes de usar Escolas ou Saúde.'
            );
        }

        const resposta = await gmRequest({
            method: metodo,
            url: TOOLBOX_API.BASE + caminho,
            headers: {
                'X-API-TOKEN': token,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: payload ? JSON.stringify(payload) : undefined
        });

        let dados = null;

        try {
            dados = JSON.parse(resposta.responseText);
        } catch (erro) {
            // Tratado abaixo.
        }

        if (resposta.status === 401 || resposta.status === 403) {
            if (!opcoes.silencioso) {
                abrirModalToken();
            }

            throw new Error(
                (dados && dados.error) ||
                'Token recusado. Gere um novo token.'
            );
        }

        if (resposta.status >= 400) {
            const trecho = String(resposta.responseText || '')
                .replace(/<[^>]*>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, 160);

            console.error(LOG, `HTTP ${resposta.status} em ${caminho}`, resposta.responseText);

            const mensagemApi = dados
                ? [dados.error || dados.message, dados.detail].filter(Boolean).join(' — ')
                : '';

            throw new Error(
                mensagemApi ||
                `Erro HTTP ${resposta.status} em ${caminho}` +
                (resposta.status >= 500
                    ? ' (erro no servidor: veja var/log do Symfony)'
                    : '') +
                (trecho ? ` — ${trecho}` : '')
            );
        }

        if (dados === null) {
            throw new Error('Resposta inválida da API do ToolboxWaze.');
        }

        return dados;
    }

    function injetarEstilos() {
        if (document.getElementById('wmt-style')) {
            return;
        }

        const css = `
            .wmt {
                color: var(--wmt-text, #e8eaed);
                font-family: 'WazeBoing', 'Helvetica Neue', Arial, sans-serif;
                font-size: 12px;
                padding: 12px;
            }

            .wmt,
            .wmt * {
                box-sizing: border-box;
            }

            .wmt h4 {
                margin: 0 0 12px;
                font-size: 14px;
                font-weight: 700;
                color: var(--wmt-title, #fff);
            }

            .wmt label {
                display: block;
                margin: 0 0 5px;
                font-size: 11px;
                font-weight: 600;
                color: var(--wmt-muted, #b8bcc2);
            }

            .wmt input,
            .wmt select {
                width: 100%;
                min-height: 32px;
                padding: 7px 9px;
                margin-bottom: 12px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 6px;
                background: var(--wmt-field, #2b2f33);
                color: var(--wmt-text, #e8eaed);
                font-size: 12px;
                outline: none;
            }

            .wmt input:focus,
            .wmt select:focus {
                border-color: var(--wmt-accent, #17b3e6);
                box-shadow: 0 0 0 2px rgba(23, 179, 230, .22);
            }

            .wmt input[type="radio"],
            .wmt input[type="checkbox"] {
                width: auto;
                min-height: 0;
                margin: 2px 0 0;
                padding: 0;
                flex: 0 0 auto;
            }

            .wmt button {
                border: 1px solid transparent;
                border-radius: 6px;
                color: #fff;
                font-weight: 700;
                font-size: 12px;
                padding: 8px 10px;
                cursor: pointer;
                transition: background .15s ease, border-color .15s ease, opacity .15s ease;
            }

            .wmt button:hover:not(:disabled) {
                filter: brightness(1.08);
            }

            .wmt button:focus-visible {
                outline: 2px solid var(--wmt-accent, #17b3e6);
                outline-offset: 2px;
            }

            .wmt button:disabled {
                opacity: .42;
                cursor: not-allowed;
            }

            .wmt-row {
                display: flex;
                gap: 8px;
                margin-bottom: 12px;
            }

            .wmt-row > button {
                flex: 1;
            }

            .wmt-primario {
                background: var(--wmt-accent, #17b3e6);
            }

            .wmt-sucesso {
                background: #2f9e5d;
            }

            .wmt-perigo {
                background: #d64545;
            }

            .wmt-neutro {
                background: #4a4f55;
            }

            .wmt-aviso {
                background: #6d4d18;
            }

            .wmt-full {
                width: 100%;
            }

            .wmt-versao {
                margin-bottom: 12px;
                text-align: center;
                font-size: 11px;
            }

            .wmt-badge {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 12px;
                color: #fff;
                font-weight: 700;
                text-decoration: none;
            }

            .wmt-tabs {
                display: flex;
                margin-bottom: 12px;
                border-bottom: 1px solid var(--wmt-border, #4a4f55);
            }

            .wmt .wmt-tab {
                flex: 1;
                background: none;
                border: none;
                border-bottom: 2px solid transparent;
                border-radius: 0;
                color: var(--wmt-muted, #b8bcc2);
                padding: 8px 0;
                font-size: 12px;
            }

            .wmt .wmt-tab.ativa {
                border-bottom-color: var(--wmt-accent, #17b3e6);
                color: var(--wmt-accent, #17b3e6);
            }

            .wmt-aviso-precisao {
                margin-bottom: 12px;
                padding: 8px;
                border: 1px solid #7c5a22;
                border-radius: 6px;
                background: #3d2f14;
                color: #ffd08a;
                font-size: 11px;
            }

            .wmt-barra {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 8px;
                margin-bottom: 12px;
                padding: 9px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 6px;
                background: var(--wmt-surface, #33383d);
            }

            .wmt-barra #wmt-contador {
                font-weight: 700;
                white-space: nowrap;
            }

            .wmt #wmt-status {
                margin-bottom: 8px;
                padding: 6px 8px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 6px;
                background: var(--wmt-surface, #33383d);
                font-size: 12px;
                font-weight: 700;
                line-height: 1.35;
                overflow-wrap: anywhere;
            }

            .wmt #wmt-status[data-nivel="erro"] {
                border-color: #d64545;
                color: #ff8a80;
            }

            .wmt #wmt-status[data-nivel="ok"] {
                border-color: #2f9e5d;
                color: #7ee2a0;
            }

            .wmt #wmt-status[data-nivel="aviso"] {
                border-color: #7c5a22;
                color: #ffd08a;
            }

            .wmt-info {
                margin-bottom: 12px;
                padding: 10px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 6px;
                background: var(--wmt-surface, #33383d);
                font-size: 11px;
                line-height: 1.5;
            }

            .wmt-info b {
                font-size: 12px;
                color: var(--wmt-title, #fff);
            }

            .wmt-info a {
                display: inline-block;
                margin: 6px 10px 0 0;
                color: var(--wmt-accent, #17b3e6);
                font-weight: 700;
                text-decoration: none;
            }

            .wmt .wmt-venue-opcao {
                display: flex;
                align-items: flex-start;
                gap: 7px;
                margin: 6px 0;
                padding: 6px 8px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 6px;
                color: var(--wmt-text, #e8eaed);
                font-size: 11px;
                font-weight: 400;
                cursor: pointer;
            }

            .wmt .wmt-venue-opcao:hover {
                border-color: var(--wmt-accent, #17b3e6);
            }

            .wmt .wmt-venue-opcao b {
                display: block;
            }

            .wmt .wmt-venue-opcao small {
                display: block;
                opacity: .75;
                overflow-wrap: anywhere;
            }

            .wmt-venue-pergunta {
                margin-bottom: 4px;
                color: #ffd08a;
                font-weight: 700;
            }

            .wmt-cmp {
                margin-top: 10px;
                padding-top: 8px;
                border-top: 1px solid var(--wmt-border, #4a4f55);
            }

            .wmt-cmp-titulo {
                margin-bottom: 6px;
                font-size: 12px;
                font-weight: 700;
                color: var(--wmt-title, #fff);
            }

            .wmt-cmp-linha {
                margin-bottom: 7px;
                padding: 6px 8px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 6px;
            }

            .wmt-cmp-topo {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 6px;
                margin-bottom: 3px;
                font-weight: 700;
            }

            .wmt-cmp-linha small {
                display: block;
                opacity: .85;
                overflow-wrap: anywhere;
            }

            .wmt-cmp-linha small.wmt-cmp-detalhe {
                color: #ffd08a;
                opacity: 1;
            }

            .wmt-chip {
                display: inline-block;
                padding: 1px 7px;
                border: 1px solid #6a7076;
                border-radius: 10px;
                font-size: 10px;
                font-weight: 700;
                white-space: nowrap;
            }

            .wmt-chip-igual {
                background: #1f4028;
                border-color: #2f9e5d;
                color: #7ee2a0;
            }

            .wmt-chip-diferente {
                background: #4a1f24;
                border-color: #d64545;
                color: #ff8a80;
            }

            .wmt-chip-parecido {
                background: #3d2f14;
                border-color: #7c5a22;
                color: #ffd08a;
            }

            .wmt-chip-ausente {
                background: #33383d;
                border-color: #8a7a4a;
                color: #e6d49a;
            }

            .wmt-chip-info {
                background: #2b2f33;
                border-color: #6a7076;
                color: #b8bcc2;
            }

            .wmt-etapas {
                margin-bottom: 12px;
                padding: 9px 10px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 6px;
                background: var(--wmt-surface, #33383d);
                font-size: 11px;
            }

            .wmt-etapas-topo {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 6px;
                margin-bottom: 6px;
            }

            .wmt-etapas-titulo {
                font-size: 12px;
                font-weight: 700;
                overflow-wrap: anywhere;
            }

            .wmt .wmt-etapas-fechar {
                padding: 0 6px;
                background: none;
                color: var(--wmt-muted, #b8bcc2);
                font-size: 12px;
            }

            .wmt-etapa {
                display: flex;
                gap: 6px;
                margin: 3px 0;
            }

            .wmt-etapa-icone {
                flex: 0 0 14px;
                width: 14px;
                font-weight: 700;
                text-align: center;
            }

            .wmt-etapa small {
                display: block;
                opacity: .8;
                overflow-wrap: anywhere;
            }

            .wmt-etapa-pendente {
                opacity: .5;
            }

            .wmt-etapa-pulada {
                opacity: .65;
            }

            .wmt-etapa-andamento .wmt-etapa-icone {
                color: var(--wmt-accent, #17b3e6);
            }

            .wmt-etapa-ok .wmt-etapa-icone {
                color: #7ee2a0;
            }

            .wmt-etapa-erro .wmt-etapa-icone,
            .wmt-etapa-erro small {
                color: #ff8a80;
            }

            .wmt-etapas button.wmt-full {
                margin-top: 8px;
            }

            .wmt-config {
                margin-bottom: 12px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 6px;
                background: var(--wmt-surface, #33383d);
            }

            .wmt-config > summary {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 6px;
                padding: 8px 10px;
                font-size: 12px;
                font-weight: 700;
                list-style: none;
                cursor: pointer;
                overflow-wrap: anywhere;
            }

            .wmt-config > summary::-webkit-details-marker {
                display: none;
            }

            .wmt-config[open] > summary {
                border-bottom: 1px solid var(--wmt-border, #4a4f55);
            }

            .wmt-config-editar {
                flex: 0 0 auto;
                opacity: .7;
            }

            .wmt-config-corpo {
                padding: 10px 10px 2px;
            }

            .wmt-filtros-contagem {
                margin: -4px 0 10px;
                font-size: 11px;
                opacity: .85;
            }

            .wmt .wmt-btn-mini {
                padding: 4px 9px;
                font-size: 11px;
            }

            .wmt .wmt-acoes {
                position: sticky;
                bottom: 0;
                z-index: 5;
                margin: 12px -12px -12px;
                padding: 10px 12px 12px;
                border-top: 1px solid var(--wmt-border, #4a4f55);
                background: var(--wmt-panel, #26292d);
                box-shadow: 0 -6px 12px rgba(0, 0, 0, .18);
            }

            .wmt .wmt-acoes .wmt-row {
                margin-bottom: 8px;
            }

            .wmt .wmt-acoes button {
                min-height: 36px;
            }

            .wmt-cmp-resumo {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                margin-bottom: 8px;
            }

            .wmt-cmp-iguais {
                margin-bottom: 7px;
            }

            .wmt-cmp-iguais > summary {
                margin-bottom: 6px;
                font-size: 11px;
                font-weight: 700;
                cursor: pointer;
                overflow-wrap: anywhere;
            }

            .wmt-cmp-compacta {
                display: flex;
                gap: 6px;
                margin: 3px 0;
                font-size: 11px;
                overflow-wrap: anywhere;
            }

            .wmt-cmp-compacta b {
                flex: 0 0 auto;
            }

            .wmt .wmt-obrig,
            .wmt .wmt-erro-texto {
                color: #ff8a80;
            }

            .wmt .wmt-aviso-texto {
                color: #ffd08a;
            }

            .wmt[data-tema="claro"] {
                --wmt-text: #1f2328;
                --wmt-title: #0d1117;
                --wmt-muted: #57606a;
                --wmt-border: #c9ced6;
                --wmt-field: #ffffff;
                --wmt-surface: #f3f5f7;
                --wmt-panel: #ffffff;
                --wmt-accent: #0b7fb3;
            }

            .wmt[data-tema="claro"] .wmt-aviso-precisao {
                border-color: #e0b659;
                background: #fff4dc;
                color: #7a5200;
            }

            .wmt[data-tema="claro"] .wmt-situacao-ativa {
                border-color: #2f9e5d;
                background: #e3f5ea;
                color: #1d6b3c;
            }

            .wmt[data-tema="claro"] .wmt-situacao-paralisada {
                border-color: #d64545;
                background: #fde8ea;
                color: #b3261e;
            }

            .wmt[data-tema="claro"] .wmt-chip-igual {
                border-color: #2f9e5d;
                background: #e3f5ea;
                color: #1d6b3c;
            }

            .wmt[data-tema="claro"] .wmt-chip-diferente {
                border-color: #d64545;
                background: #fde8ea;
                color: #b3261e;
            }

            .wmt[data-tema="claro"] .wmt-chip-parecido {
                border-color: #e0b659;
                background: #fff4dc;
                color: #7a5200;
            }

            .wmt[data-tema="claro"] .wmt-chip-ausente {
                border-color: #d4b86a;
                background: #f7f0dc;
                color: #6b5a1f;
            }

            .wmt[data-tema="claro"] .wmt-chip-info {
                border-color: #c9ced6;
                background: #eef0f3;
                color: #57606a;
            }

            .wmt[data-tema="claro"] .wmt-cmp-linha small.wmt-cmp-detalhe {
                color: #8a5a00;
            }

            .wmt[data-tema="claro"] #wmt-status[data-nivel="erro"] {
                color: #b3261e;
            }

            .wmt[data-tema="claro"] #wmt-status[data-nivel="ok"] {
                color: #1d6b3c;
            }

            .wmt[data-tema="claro"] #wmt-status[data-nivel="aviso"] {
                color: #8a5a00;
            }

            .wmt[data-tema="claro"] .wmt-etapa-ok .wmt-etapa-icone {
                color: #1d6b3c;
            }

            .wmt[data-tema="claro"] .wmt-etapa-erro .wmt-etapa-icone,
            .wmt[data-tema="claro"] .wmt-etapa-erro small {
                color: #b3261e;
            }

            .wmt[data-tema="claro"] .wmt-bar {
                background: rgba(0, 0, 0, .12);
            }

            .wmt[data-tema="claro"] .wmt-obrig,
            .wmt[data-tema="claro"] .wmt-erro-texto {
                color: #b3261e;
            }

            .wmt[data-tema="claro"] .wmt-aviso-texto {
                color: #8a5a00;
            }

            .wmt[data-tema="claro"] .wmt-acoes {
                box-shadow: 0 -6px 12px rgba(0, 0, 0, .08);
            }

            .wmt-dado {
                margin: 6px 0;
                overflow-wrap: anywhere;
            }

            .wmt-situacao {
                display: inline-block;
                padding: 2px 7px;
                border: 1px solid #6a7076;
                border-radius: 6px;
                font-weight: 700;
            }

            .wmt-situacao-ativa {
                background: #1f4028;
                color: #7ee2a0;
                border-color: #2f9e5d;
            }

            .wmt-situacao-paralisada {
                background: #4a1f24;
                color: #ff8a80;
                border-color: #d64545;
            }

            .wmt-restricao {
                margin: 8px 0;
            }

            .wmt-restricao summary {
                cursor: pointer;
            }

            .wmt-restricao div {
                margin-top: 5px;
                overflow-wrap: anywhere;
            }

            .wmt-token-status {
                margin-bottom: 7px;
                font-size: 11px;
                opacity: .8;
            }

            .wmt-progresso {
                margin-top: 12px;
                text-align: center;
                font-weight: 700;
            }

            .wmt-prog-linha {
                margin-bottom: 9px;
            }

            .wmt-prog-topo {
                display: flex;
                justify-content: space-between;
                margin-bottom: 3px;
                font-size: 11px;
            }

            .wmt-bar {
                height: 7px;
                overflow: hidden;
                border-radius: 4px;
                background: rgba(255, 255, 255, .12);
            }

            .wmt-bar > i {
                display: block;
                width: 0;
                height: 100%;
                transition: width .4s;
            }

            .wmt-top {
                margin-bottom: 9px;
                padding: 9px 10px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 6px;
                background: var(--wmt-surface, #33383d);
            }

            .wmt-top-titulo {
                margin-bottom: 4px;
                font-size: 12px;
                font-weight: 700;
            }

            .wmt-top-linha {
                display: flex;
                justify-content: space-between;
                padding: 2px 0;
                font-size: 11px;
            }

            .wmt-vazio {
                font-size: 11px;
                opacity: .6;
            }

            .wmt-rodape {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 12px;
            }

            .wmt-rodape span {
                font-size: 10px;
                opacity: .7;
            }

            .wmt-flutuante {
                position: fixed;
                right: 12px;
                bottom: 12px;
                z-index: 9999;
                width: 300px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 10px;
                background: var(--wmt-panel, #26292d);
                color: var(--wmt-text, #e8eaed);
                box-shadow: 0 6px 24px rgba(0, 0, 0, .45);
            }

            .wmt-overlay {
                position: fixed;
                inset: 0;
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(0, 0, 0, .62);
            }

            .wmt-modal {
                width: 430px;
                max-width: 92%;
                max-height: 90vh;
                overflow-y: auto;
                padding: 20px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 10px;
                background: var(--wmt-panel, #26292d);
                color: var(--wmt-text, #e8eaed);
                font-family: 'WazeBoing', 'Helvetica Neue', Arial, sans-serif;
                font-size: 12px;
            }

            .wmt-modal h3 {
                margin: 0 0 12px;
                color: var(--wmt-title, #fff);
            }

            .wmt-modal p {
                margin: 0 0 10px;
                line-height: 1.5;
            }

            .wmt-modal ul {
                margin: 0 0 12px;
                padding-left: 20px;
                font-size: 12px;
                line-height: 1.55;
            }

            .wmt-modal .wmt-modal-motivos {
                padding: 8px 10px 8px 26px;
                border: 1px solid #7c5a22;
                border-radius: 6px;
                background: #3d2f14;
                color: #ffd08a;
            }

            .wmt-modal ol {
                margin: 0 0 16px;
                padding-left: 20px;
                font-size: 12px;
                line-height: 1.6;
            }

            .wmt-modal a.wmt-btn-link {
                display: block !important;
                margin-bottom: 14px;
                padding: 10px;
                border-radius: 6px;
                background: var(--wmt-accent, #17b3e6) !important;
                color: #fff !important;
                font-size: 13px !important;
                font-weight: 700;
                line-height: 1.3 !important;
                text-align: center;
                text-decoration: none !important;
            }

            .wmt-modal label {
                display: block;
                margin-bottom: 6px;
                font-size: 12px;
                font-weight: 700;
            }

            .wmt-modal input {
                width: 100%;
                padding: 9px;
                border: 1px solid var(--wmt-border, #4a4f55);
                border-radius: 6px;
                background: var(--wmt-field, #2b2f33);
                color: var(--wmt-text, #e8eaed);
            }

            .wmt-modal-status {
                min-height: 16px;
                margin-top: 8px;
                font-size: 12px;
                color: var(--wmt-muted, #b8bcc2);
            }

            .wmt-modal-botoes {
                display: flex;
                gap: 8px;
                margin-top: 14px;
            }

            .wmt-modal-botoes button {
                flex: 1;
                padding: 10px;
                border: 1px solid transparent;
                border-radius: 6px;
                color: #fff;
                font-size: 12px;
                font-weight: 700;
                cursor: pointer;
            }

            .wmt-modal-botoes button:focus-visible {
                outline: 2px solid var(--wmt-accent, #17b3e6);
                outline-offset: 2px;
            }
        `;

        const estilo = document.createElement('style');
        estilo.id = 'wmt-style';
        estilo.textContent = css;
        document.head.appendChild(estilo);
    }

    function abrirModalToken() {
        if (document.getElementById('wmt-token-modal')) {
            return;
        }

        const overlay = document.createElement('div');
        overlay.id = 'wmt-token-modal';
        overlay.className = 'wmt-overlay';

        overlay.innerHTML = `
            <div class="wmt-modal" role="dialog" aria-modal="true" aria-labelledby="wmt-modal-titulo">
                <h3 id="wmt-modal-titulo">🔑 Token da API</h3>
                <ol>
                    <li>Clique em <b>Abrir página do token</b>.</li>
                    <li>Faça login no ToolboxWaze, se necessário.</li>
                    <li>Clique em <b>Gerar novo token</b>.</li>
                    <li>Copie o token gerado.</li>
                    <li>Cole no campo abaixo e clique em <b>Validar e salvar</b>.</li>
                </ol>
                <a class="wmt-btn-link" href="${TOOLBOX_API.TOKEN_PAGE}" target="_blank" rel="noopener noreferrer">Abrir página do token</a>
                <label for="wmt-token-input">Cole seu token:</label>
                <input type="password" id="wmt-token-input" placeholder="Cole aqui o token" autocomplete="off" spellcheck="false">
                <div class="wmt-modal-status" id="wmt-token-modal-status"></div>
                <div class="wmt-modal-botoes">
                    <button type="button" id="wmt-token-salvar" class="wmt-sucesso">Validar e salvar</button>
                    <button type="button" id="wmt-token-fechar" class="wmt-neutro">Fechar</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        const input = overlay.querySelector('#wmt-token-input');
        const status = overlay.querySelector('#wmt-token-modal-status');
        const botaoSalvar = overlay.querySelector('#wmt-token-salvar');
        const botaoFechar = overlay.querySelector('#wmt-token-fechar');

        const aoPressionarTecla = evento => {
            if (evento.key === 'Escape') {
                evento.stopPropagation();
                fechar();
            }
        };

        function fechar() {
            document.removeEventListener('keydown', aoPressionarTecla, true);
            overlay.remove();
        }

        const mostrar = (texto, cor) => {
            status.textContent = texto;
            status.style.color = cor;
        };

        async function salvar() {
            const token = input.value.trim();

            if (!token) {
                mostrar('Cole o token gerado no site.', '#ff8a80');
                return;
            }

            botaoSalvar.disabled = true;
            mostrar('Validando token...', '#b8bcc2');

            try {
                const dados = await validarToken(token);

                salvarToken(token);
                mostrar(
                    `Token válido para ${dados.username || 'seu usuário'}.`,
                    '#7ee2a0'
                );

                atualizarStatusToken();

                const campoUsuario = ui('wmt-usuario');

                if (
                    campoUsuario &&
                    !campoUsuario.value.trim() &&
                    dados.username
                ) {
                    campoUsuario.value = dados.username;
                    store.set(KEYS.USUARIO, dados.username);
                }

                setTimeout(fechar, 1200);

                // Com o token salvo, já carrega o lote e as opções de filtro.
                carregarFacetas();

                if (
                    TIPOS[ui('wmt-tipo').value]?.api &&
                    ui('wmt-uf').value &&
                    ui('wmt-usuario').value.trim() &&
                    state.lote.length === 0 &&
                    !state.carregando
                ) {
                    carregarLote();
                }
            } catch (erro) {
                mostrar(erro.message, '#ff8a80');
                botaoSalvar.disabled = false;
            }
        }

        botaoSalvar.addEventListener('click', salvar);
        botaoFechar.addEventListener('click', fechar);
        input.addEventListener('keydown', evento => {
            if (evento.key === 'Enter') {
                salvar();
            }
        });

        overlay.addEventListener('mousedown', evento => {
            if (evento.target === overlay) {
                fechar();
            }
        });

        document.addEventListener('keydown', aoPressionarTecla, true);
        input.focus();
    }

    /**
     * Resumo de confirmação exibido antes de enviar quando há risco de vincular
     * o Place errado. Resolve true (confirmar) ou false (voltar/Esc/clique fora).
     */
    function confirmarResumo({ titulo, paragrafosHtml, itensHtml, motivos }) {
        return new Promise(resolve => {
            if (document.getElementById('wmt-resumo-modal')) {
                resolve(false);
                return;
            }

            const overlay = document.createElement('div');
            overlay.id = 'wmt-resumo-modal';
            overlay.className = 'wmt-overlay';

            overlay.innerHTML = `
                <div class="wmt-modal" role="dialog" aria-modal="true" aria-labelledby="wmt-resumo-titulo">
                    <h3 id="wmt-resumo-titulo">${esc(titulo)}</h3>
                    ${paragrafosHtml.map(p => `<p>${p}</p>`).join('')}
                    <ul>${itensHtml.map(i => `<li>${i}</li>`).join('')}</ul>
                    <p><b>Por que este resumo aparece:</b></p>
                    <ul class="wmt-modal-motivos">${motivos.map(m => `<li>${esc(m)}</li>`).join('')}</ul>
                    <div class="wmt-modal-botoes">
                        <button type="button" id="wmt-resumo-ok" class="wmt-sucesso">Confirmar e enviar</button>
                        <button type="button" id="wmt-resumo-voltar" class="wmt-neutro">Voltar</button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);

            function finalizar(valor) {
                document.removeEventListener('keydown', aoPressionarTecla, true);
                overlay.remove();
                resolve(valor);
            }

            function aoPressionarTecla(evento) {
                if (evento.key === 'Escape') {
                    evento.stopPropagation();
                    finalizar(false);
                }
            }

            overlay.querySelector('#wmt-resumo-ok')
                .addEventListener('click', () => finalizar(true));

            overlay.querySelector('#wmt-resumo-voltar')
                .addEventListener('click', () => finalizar(false));

            overlay.addEventListener('mousedown', evento => {
                if (evento.target === overlay) {
                    finalizar(false);
                }
            });

            document.addEventListener('keydown', aoPressionarTecla, true);
            overlay.querySelector('#wmt-resumo-voltar').focus();
        });
    }

    function atualizarStatusToken() {
        const elemento = ui('wmt-token-status');

        if (elemento) {
            elemento.textContent = obterToken()
                ? 'Token salvo neste navegador.'
                : 'Nenhum token salvo.';
        }

        atualizarResumoConfig();
    }

    /** Linha de resumo da configuração (visível mesmo com o bloco recolhido). */
    function atualizarResumoConfig() {
        const resumo = ui('wmt-config-resumo');

        if (!resumo) {
            return;
        }

        const usuario = String(ui('wmt-usuario').value || '').trim();
        const tipo = TIPOS[ui('wmt-tipo').value];
        const uf = ui('wmt-uf').value;
        const partes = [
            usuario || 'sem usuário',
            tipo ? tipo.rotulo : 'sem tipo',
            uf || 'sem UF'
        ];

        if (tipo && tipo.api && !obterToken()) {
            partes.push('⚠ sem token');
        }

        resumo.textContent = partes.join(' · ');
    }

    function abrirConfiguracao(abrir = true) {
        const bloco = ui('wmt-config');

        if (bloco) {
            bloco.open = Boolean(abrir);
        }
    }

    function abrirFiltros(abrir = true) {
        const bloco = ui('wmt-filtros-bloco');

        if (bloco) {
            bloco.open = Boolean(abrir);
        }
    }

    // ---------------------------------------------------------------------
    // Filtros da Saúde (a API responde ao filtro escolhido)
    // ---------------------------------------------------------------------

    function lerFiltrosUi() {
        const filtros = {};

        Object.keys(FILTRO_IDS).forEach(chave => {
            const campo = ui(FILTRO_IDS[chave]);

            filtros[chave] = campo
                ? String(campo.value || '').trim()
                : FILTROS_PADRAO[chave];
        });

        return filtros;
    }

    function aplicarFiltrosNaUi(filtros) {
        Object.keys(FILTRO_IDS).forEach(chave => {
            const campo = ui(FILTRO_IDS[chave]);

            if (!campo) {
                return;
            }

            let valor = filtros[chave] === undefined || filtros[chave] === null
                ? FILTROS_PADRAO[chave]
                : String(filtros[chave]);

            if (campo.tagName === 'SELECT') {
                const existe = Array.from(campo.options).some(o => o.value === valor);

                if (!existe) {
                    if ((chave === 'municipio' || chave === 'tipo') && valor) {
                        campo.add(new Option(valor, valor));
                    } else {
                        valor = FILTROS_PADRAO[chave];
                    }
                }
            }

            campo.value = valor;
        });
    }

    /** Filtros enviados à API: só para Saúde; null para os demais tipos. */
    function filtrosAtuaisApi() {
        const tipo = TIPOS[ui('wmt-tipo').value];

        return tipo && tipo.api === 'saude'
            ? lerFiltrosUi()
            : null;
    }

    function mesmosFiltros(a, b) {
        return JSON.stringify(a || null) === JSON.stringify(b || null);
    }

    function textoResumoFiltros() {
        const filtros = lerFiltrosUi();
        const partes = [];

        if (filtros.tipo) {
            partes.push(filtros.tipo);
        }

        if (filtros.municipio) {
            partes.push(filtros.municipio);
        }

        const base = partes.length ? partes.join(' · ') : 'Todos os tipos e municípios';

        return state.facetas.disponiveis !== null
            ? `${base} · ${state.facetas.disponiveis} disponíveis`
            : base;
    }

    function atualizarResumoFiltros() {
        const resumo = ui('wmt-filtros-resumo');
        const contagem = ui('wmt-f-contagem');

        if (resumo) {
            resumo.textContent = 'Filtros: ' + textoResumoFiltros();
        }

        if (contagem) {
            contagem.textContent = state.facetas.disponiveis !== null
                ? `${state.facetas.disponiveis} estabelecimento(s) disponível(is) com estes filtros.`
                : '';
        }
    }

    function preencherOpcoes(idCampo, rotuloTodos, lista) {
        const select = ui(idCampo);

        if (!select) {
            return;
        }

        const atual = select.value;

        let html = `<option value="">${esc(rotuloTodos)}</option>` + lista
            .map(o => `<option value="${esc(o.nome)}">${esc(o.nome)} (${esc(o.total)})</option>`)
            .join('');

        if (atual && !lista.some(o => o.nome.toLowerCase() === atual.toLowerCase())) {
            html += `<option value="${esc(atual)}">${esc(atual)} (0)</option>`;
        }

        select.innerHTML = html;

        // Casa sem diferenciar maiúsculas (a API compara o tipo sem diferenciar).
        const igual = Array.from(select.options).find(o => o.value.toLowerCase() === atual.toLowerCase());

        select.value = igual ? igual.value : '';
    }

    function preencherMunicipios() {
        preencherOpcoes('wmt-f-municipio', 'Todos os municípios', state.facetas.municipios);
    }

    function preencherTipos() {
        preencherOpcoes('wmt-f-tipo', 'Todos os tipos', state.facetas.tipos);
    }

    /** Pede à API as opções de filtro (municípios e contagem) para a seleção atual. */
    async function carregarFacetas() {
        const tipo = TIPOS[ui('wmt-tipo').value];
        const uf = ui('wmt-uf').value;

        if (!tipo || tipo.api !== 'saude' || !uf || !obterToken()) {
            state.facetas = { tipos: [], municipios: [], disponiveis: null };
            atualizarResumoFiltros();
            return;
        }

        const sequencia = ++state.facetaSeq;
        const filtros = lerFiltrosUi();
        const consulta = new URLSearchParams();

        Object.keys(filtros).forEach(chave => {
            if (filtros[chave]) {
                consulta.set(chave, filtros[chave]);
            }
        });

        try {
            const resposta = await chamarApi(
                'GET',
                `/api/saude/uf/${encodeURIComponent(uf)}/filtros?${consulta.toString()}`,
                null,
                { silencioso: true }
            );

            if (sequencia !== state.facetaSeq) {
                return;
            }

            const disponiveis = Number(resposta.disponiveis);

            state.facetas = {
                tipos: Array.isArray(resposta.tipos) ? resposta.tipos : [],
                municipios: Array.isArray(resposta.municipios) ? resposta.municipios : [],
                disponiveis: Number.isFinite(disponiveis) ? disponiveis : null
            };

            preencherTipos();
            preencherMunicipios();
        } catch (erro) {
            if (sequencia !== state.facetaSeq) {
                return;
            }

            state.facetas.disponiveis = null;
            console.warn(LOG, 'Não foi possível carregar as opções de filtro:', erro.message);
        }

        atualizarResumoFiltros();
    }

    /** Qualquer mudança de filtro: salva, libera a reserva antiga e recarrega o lote. */
    async function aoMudarFiltro() {
        store.setJson(KEYS.FILTROS, lerFiltrosUi());
        atualizarResumoFiltros();
        state.loadSeq++;
        resetarLote();
        await liberarReservaSeMudou();
        carregarFacetas();
        carregarLote();
    }

    /** Detecta o tema (claro/escuro) do fundo do painel do WME e ajusta as cores. */
    function detectarTema() {
        const painel = state.painel;

        if (!painel || typeof painel.setAttribute !== 'function') {
            return;
        }

        let claro = null;

        try {
            let elemento = painel.parentElement;

            while (elemento && claro === null) {
                const cor = window.getComputedStyle(elemento).backgroundColor;
                const partes = /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s/]+([\d.]+))?/.exec(cor || '');

                if (partes && (partes[4] === undefined || parseFloat(partes[4]) > 0.05)) {
                    claro = (0.299 * partes[1] + 0.587 * partes[2] + 0.114 * partes[3]) > 150;
                }

                elemento = elemento.parentElement;
            }
        } catch (erro) {
            claro = null;
        }

        painel.setAttribute('data-tema', claro === true ? 'claro' : 'escuro');
    }

    function htmlPainel() {
        const opcoesTipo = Object.keys(TIPOS)
            .map(chave => (
                `<option value="${chave}">${esc(TIPOS[chave].rotulo)}</option>`
            ))
            .join('');

        const linhasProgresso = RANK_TIPOS.map(tipo => `
            <div class="wmt-prog-linha" data-tipo="${tipo.chave}">
                <div class="wmt-prog-topo">
                    <span>${tipo.emoji} ${esc(tipo.rotulo)}</span>
                    <b class="wmt-prog-pct">0%</b>
                </div>
                <div class="wmt-bar">
                    <i style="background:${tipo.cor};"></i>
                </div>
            </div>
        `).join('');

        return `
            <h4>Inspetor de Tarefas</h4>
            <div id="wmt-versao" class="wmt-versao"></div>

            <div class="wmt-tabs">
                <button type="button" id="wmt-nav-atividade" class="wmt-tab ativa">📋 Atividade</button>
                <button type="button" id="wmt-nav-ranking" class="wmt-tab">🏆 Ranking</button>
            </div>

            <div id="wmt-aba-atividade">
                <details id="wmt-config" class="wmt-config" open>
                    <summary>
                        <span>⚙ <span id="wmt-config-resumo">Configuração</span></span>
                        <span class="wmt-config-editar" aria-hidden="true">✎</span>
                    </summary>

                    <div class="wmt-config-corpo">
                        <div id="wmt-bloco-token" style="display:none;">
                            <label>Token da API (necessário para Escolas e Saúde):</label>
                            <div id="wmt-token-status" class="wmt-token-status">Verificando...</div>

                            <div class="wmt-row">
                                <button type="button" id="wmt-btn-token" class="wmt-primario">🔑 Token da API</button>
                                <button type="button" id="wmt-btn-remover-token" class="wmt-neutro">Remover</button>
                            </div>
                        </div>

                        <label for="wmt-usuario">Nome do Usuário <span class="wmt-obrig">*</span>:</label>
                        <input type="text" id="wmt-usuario" placeholder="Seu nome de editor (obrigatório)" autocomplete="off">

                        <label for="wmt-tipo">Tipo de Tarefa:</label>
                        <select id="wmt-tipo">${opcoesTipo}</select>

                        <label for="wmt-uf">Estado (UF):</label>
                        <select id="wmt-uf"></select>

                        <button type="button" id="wmt-btn-limpar" class="wmt-neutro wmt-full" style="display:none; margin-bottom:10px; font-size:11px;">🧹 Limpar reserva local</button>
                    </div>
                </details>

                <details id="wmt-filtros-bloco" class="wmt-config" style="display:none;">
                    <summary>
                        <span>🔍 <span id="wmt-filtros-resumo">Filtros</span></span>
                        <span class="wmt-config-editar" aria-hidden="true">✎</span>
                    </summary>

                    <div class="wmt-config-corpo">
                        <label for="wmt-f-tipo">Tipo de estabelecimento:</label>
                        <select id="wmt-f-tipo">
                            <option value="">Todos os tipos</option>
                        </select>

                        <label for="wmt-f-municipio">Município:</label>
                        <select id="wmt-f-municipio">
                            <option value="">Todos os municípios</option>
                        </select>

                        <div id="wmt-f-contagem" class="wmt-filtros-contagem" role="status"></div>

                        <button type="button" id="wmt-f-limpar" class="wmt-neutro wmt-full wmt-btn-mini" style="margin-bottom:10px;">Limpar filtros</button>
                    </div>
                </details>

                <div id="wmt-aviso-precisao" class="wmt-aviso-precisao" style="display:none;">
                    📍 Localização da sinalização. Uma análise mais precisa é necessária.
                </div>

                <div class="wmt-barra">
                    <span id="wmt-contador">0/0</span>
                    <button type="button" id="wmt-btn-centralizar" class="wmt-neutro wmt-btn-mini" title="Voltar ao ponto desta tarefa no mapa" disabled>🎯 Centralizar</button>
                </div>

                <div id="wmt-info" class="wmt-info" style="display:none;"></div>
                <div id="wmt-venue" class="wmt-info" style="display:none;"></div>
                <div id="wmt-etapas" class="wmt-etapas" style="display:none;"></div>

                <div id="wmt-progresso" class="wmt-progresso"></div>

                <div id="wmt-acoes" class="wmt-acoes">
                    <div id="wmt-status" role="status" aria-live="polite">Inicializando...</div>

                    <div class="wmt-row">
                        <button type="button" id="wmt-btn-resolvido" class="wmt-sucesso" disabled>✓ RESOLVIDO</button>
                        <button type="button" id="wmt-btn-naolocalizado" class="wmt-perigo" disabled>❌ Ñ ACHADO</button>
                    </div>

                    <button type="button" id="wmt-btn-pular" class="wmt-aviso wmt-full" style="display:none;" disabled>⏭ PULAR</button>
                </div>
            </div>

            <div id="wmt-aba-ranking" style="display:none;">
                <div style="font-weight:700; margin-bottom:8px;">📊 Progresso Geral por Tipo</div>
                ${linhasProgresso}
                <div id="wmt-ranking-tops" style="margin-top:12px;"></div>

                <div class="wmt-rodape">
                    <span id="wmt-ranking-atualizado"></span>
                    <button type="button" id="wmt-ranking-refresh" class="wmt-neutro">🔄 Atualizar</button>
                </div>
            </div>
        `;
    }

    async function criarPainel() {
        const container=document.createElement('div');
        container.id='wmt-container'; container.className='wmt wmt-flutuante';
        container.innerHTML='<div id="wmt-drag" style="display:flex;align-items:center;justify-content:space-between;cursor:move;padding:8px;border-bottom:1px solid #888"><strong>Tarefas — painel independente</strong><button type="button" id="wmt-collapse" class="wmt-neutro">Recolher</button></div><div id="wmt-body">'+htmlPainel()+'</div>';
        Object.assign(container.style,{top:'75px',right:'15px',bottom:'auto',width:'370px',maxWidth:'95vw',maxHeight:'85vh',overflowY:'auto',zIndex:'10000'});
        document.body.appendChild(container); state.painel=container;
        const body=container.querySelector('#wmt-body');
        container.querySelector('#wmt-collapse').onclick=e=>{body.hidden=!body.hidden;e.target.textContent=body.hidden?'Expandir':'Recolher';};
        const bar=container.querySelector('#wmt-drag'); let drag=null;
        bar.style.touchAction='none';
        bar.addEventListener('pointerdown',e=>{if(e.target.closest('button')||e.button!==0)return;const r=container.getBoundingClientRect();drag={x:e.clientX-r.left,y:e.clientY-r.top};bar.setPointerCapture(e.pointerId);e.preventDefault();});
        bar.addEventListener('pointermove',e=>{if(!drag)return;container.style.left=Math.max(0,Math.min(innerWidth-container.offsetWidth,e.clientX-drag.x))+'px';container.style.top=Math.max(0,Math.min(innerHeight-45,e.clientY-drag.y))+'px';container.style.right='auto';});
        ['pointerup','pointercancel','lostpointercapture'].forEach(n=>bar.addEventListener(n,()=>{drag=null;}));
        container.addEventListener('click',async e=>{
            if(!e.target.closest('[data-validacao-copy]'))return;
            const item=state.lote[state.indice];
            if(state.salvando || state.carregando)return;
            const v=item?.venues?.encontrados.find(v=>String(v.id)===String(item.venues.escolha));
            if(!v)return;
            const texto=linhasValidacao(item,v).map(l=>l.campo+': '+(l.campo==='Nome'?nomeTitulo(l.fonte):l.fonte)).join('\n');
            try {await navigator.clipboard.writeText(texto);setStatus('Diferenças copiadas para colagem manual. Revise os alertas antes de aplicar.','ok');}
            catch(erro){setStatus('Falha ao copiar: '+erro.message,'erro');}
        });
        const css=document.createElement('style');
        css.textContent='#wmt-container .wmt-cmp-linha small{opacity:1;color:var(--wmt-text,#e8eaed)}';
        document.head.appendChild(css);
    }


    function popularUFs() {
        const select = ui('wmt-uf');

        select.innerHTML = (
            '<option value="">Selecione uma UF</option>' +
            UFS_BRASIL.map(uf => `<option value="${uf}">${uf}</option>`).join('')
        );
    }

    function restaurarPreferencias() {
        const usuarioSalvo = String(store.get(KEYS.USUARIO, '') || '');
        let usuario = usuarioSalvo;

        if (!usuario) {
            try {
                const informacao = state.sdk.State.getUserInfo();

                if (informacao && informacao.userName) {
                    usuario = informacao.userName;
                }
            } catch (erro) {
                // SDK sem getUserInfo ou usuário não logado.
            }
        }

        ui('wmt-usuario').value = usuario;

        const tipo = String(store.get(KEYS.TIPO, '') || '');

        if (TIPOS[tipo]) {
            ui('wmt-tipo').value = tipo;
        }

        const uf = String(store.get(KEYS.UF, '') || '');

        if (UFS_BRASIL.includes(uf)) {
            ui('wmt-uf').value = uf;
        }

        const filtrosSalvos = store.getJson(KEYS.FILTROS);

        aplicarFiltrosNaUi(
            Object.assign(
                {},
                FILTROS_PADRAO,
                filtrosSalvos && typeof filtrosSalvos === 'object' ? filtrosSalvos : {}
            )
        );
    }

    function atualizarVisibilidadePorTipo() {
        const configuracao = TIPOS[ui('wmt-tipo').value] || {};
        const usaApi = Boolean(configuracao.api);

        ui('wmt-aviso-precisao').style.display = (
            configuracao.avisoPrecisao ? 'block' : 'none'
        );

        ui('wmt-bloco-token').style.display = (
            usaApi ? 'block' : 'none'
        );

        ui('wmt-filtros-bloco').style.display = (
            configuracao.api === 'saude' ? 'block' : 'none'
        );

        ui('wmt-btn-pular').style.display = (
            usaApi ? 'block' : 'none'
        );

        ui('wmt-btn-resolvido').textContent = (
            configuracao.api === 'saude'
                ? '✓ CONFIRMADO'
                : configuracao.api === 'escolas'
                    ? '✓ APROVADO'
                    : '✓ RESOLVIDO'
        );

        ui('wmt-btn-naolocalizado').textContent = (
            configuracao.api
                ? '❌ NÃO LOCALIZADO'
                : '❌ Ñ ACHADO'
        );

        ui('wmt-btn-limpar').style.display = (
            usaApi ? 'block' : 'none'
        );

        atualizarResumoConfig();
        atualizarResumoFiltros();
    }

    function setStatus(mensagem, nivel) {
        const elemento = ui('wmt-status');

        if (!elemento) {
            return;
        }

        elemento.textContent = mensagem;
        elemento.dataset.nivel = nivel || '';
    }

    function desabilitarAcoes() {
        [
            'wmt-btn-resolvido',
            'wmt-btn-naolocalizado',
            'wmt-btn-pular'
        ].forEach(id => {
            ui(id).disabled = true;
        });
    }

    function habilitarAcoesDeEnvio() {
        [
            'wmt-btn-resolvido',
            'wmt-btn-naolocalizado',
            'wmt-btn-pular'
        ].forEach(id => {
            ui(id).disabled = false;
        });
    }

    function bloquearControlesEnvio(bloquear) {
        IDS_CONTROLES_ENVIO.forEach(id => {
            const campo = ui(id);

            if (campo) {
                campo.disabled = bloquear;
            }
        });
    }

    function definirCarregando(valor) {
        state.carregando = valor;

        [
            'wmt-uf',
            'wmt-tipo',
            'wmt-usuario',
            'wmt-f-tipo',
            'wmt-f-municipio',
            'wmt-f-limpar'
        ].forEach(id => {
            const campo = ui(id);

            if (campo) {
                campo.disabled = valor;
            }
        });

        if (valor) {
            desabilitarAcoes();
        }
    }

    function nomeDaTarefa(item) {
        if (item.tipo === 'ESCOLAS') {
            return String(item.escola || 'Escola');
        }

        if (item.tipo === 'SAUDE') {
            return String(item.nome || 'Estabelecimento de saúde');
        }

        return (TIPOS[item.tipo] || {}).rotulo || 'Tarefa';
    }

    function renderizarInfoTarefa(item) {
        const elemento = ui('wmt-info');

        if (!item) {
            elemento.style.display = 'none';
            elemento.innerHTML = '';
            return;
        }

        let html;

        if (item.tipo === 'ESCOLAS') {
            const linkWaze = urlSegura(item.linkWaze);
            const linkArea = urlSegura(item.linkAreaEscolar);
            const linkDetalhes = item.id
                ? `${TOOLBOX_API.BASE}/escolas/${encodeURIComponent(item.id)}`
                : '';

            const funcionamento = String(
                item.situacaoFuncionamento || ''
            ).trim();

            const situacaoClasse = funcionamento.toLocaleLowerCase('pt-BR') === 'paralisada'
                ? 'wmt-situacao-paralisada'
                : (
                    funcionamento.toLocaleLowerCase('pt-BR') === 'ativa'
                        ? 'wmt-situacao-ativa'
                        : ''
                );

            const telefone = String(item.telefone || '').trim();
            const telefoneDiscavel = telefone.replace(/[^0-9+]/g, '');
            const telefoneEscolaHtml = telefone && /[0-9]/.test(telefoneDiscavel)
                ? `<a href="tel:${esc(telefoneDiscavel)}">${esc(telefone)}</a>`
                : esc(telefone || 'Não informado');

            html = `<b>${esc(item.escola || 'Escola')}</b><br>` +
                (item.municipio ? `${esc(item.municipio)}<br>` : '') +
                (item.endereco ? `${esc(item.endereco)}<br>` : '') +
                (item.codigoInep ? `INEP: ${esc(item.codigoInep)}<br>` : '') +
                `<div class="wmt-dado">Funcionamento: <span class="wmt-situacao ${situacaoClasse}">${esc(funcionamento || 'Não informado')}</span></div>` +
                `<div class="wmt-dado">Telefone: ${telefoneEscolaHtml}</div>` +
                (item.restricaoAtendimento ? `<details class="wmt-restricao"><summary>Ver informação original da escola</summary><div>${esc(item.restricaoAtendimento)}</div></details>` : '') +
                (linkDetalhes ? `<a href="${esc(linkDetalhes)}" target="_blank" rel="noopener noreferrer">🔎 Mais dados</a>` : '') +
                (linkWaze ? `<a href="${esc(linkWaze)}" target="_blank" rel="noopener noreferrer">Abrir no Waze</a>` : '') +
                (linkArea ? `<a href="${esc(linkArea)}" target="_blank" rel="noopener noreferrer">Área escolar</a>` : '');
        } else if (item.tipo === 'SAUDE') {
            const linkWaze = urlSegura(item.linkWaze);
            const linkDetalhes = item.id
                ? `${TOOLBOX_API.BASE}/saude/${encodeURIComponent(item.id)}`
                : '';

            html = `<b>${esc(item.nome || 'Estabelecimento de saúde')}</b><br>` +
                (item.municipio ? `${esc(item.municipio)}<br>` : '') +
                (item.endereco ? `${esc(item.endereco)}<br>` : '') +
                (item.cnes ? `CNES: ${esc(item.cnes)}<br>` : '') +
                (item.categoria ? `Tipo: ${esc(item.categoria)}<br>` : '') +
                (item.situacao ? `Funcionamento CNES: ${esc(item.situacao)}<br>` : '') +
                (item.statusRevisao ? `Revisão: ${esc(item.statusRevisao)}<br>` : '') +
                `<div class="wmt-dado">Telefone da saúde (fonte/API): ${telefoneHtml(item.telefone)}</div>` +
                (linkDetalhes ? `<a href="${esc(linkDetalhes)}" target="_blank" rel="noopener noreferrer">🔎 Mais dados</a>` : '') +
                (linkWaze ? `<a href="${esc(linkWaze)}" target="_blank" rel="noopener noreferrer">Abrir no Waze</a>` : '');
        } else {
            const rotulo = (TIPOS[state.tipoLote] || {}).rotulo || '';
            html = `<b>${esc(rotulo)}</b> · ${esc(item.coords || 'sem coordenadas')}`;
        }

        elemento.innerHTML = html;
        elemento.style.display = 'block';
    }

    // ---------------------------------------------------------------------
    // Place (venue) do WME vinculado ao link_waze
    // ---------------------------------------------------------------------

    /** Extrai os IDs de venues do parâmetro `venues=` (separados por vírgula). */
    function extrairVenueIds(url) {
        if (!url) return [];
        try {
            const parsed = new URL(String(url), 'https://www.waze.com');
            if (!/(^|\.)waze\.com$/i.test(parsed.hostname)) return [];
            return Array.from(new Set((parsed.searchParams.get('venues') || '')
                .split(',').map(id => id.trim())
                .filter(id => /^[A-Za-z0-9._:-]{1,120}$/.test(id))));
        } catch (erro) { return []; }
    }

    function textoLimitado(valor, maximo) {
        if (valor === null || valor === undefined || typeof valor === 'object') return null;
        return String(valor).trim().slice(0, maximo) || null;
    }

    function telefoneHtml(valor, vazio = 'Não informado pela API') {
        const telefone = textoLimitado(valor, 80);
        if (!telefone) return esc(vazio);
        const digitos = telefone.replace(/\D/g, '');
        if (!digitos) return esc(telefone);
        const discavel = (telefone.trim().startsWith('+') ? '+' : '') + digitos;
        return `<a href="tel:${esc(discavel)}">${esc(telefone)}</a>`;
    }

    function normalizarAtivo(valor) {
        if (valor === true || valor === 1 || valor === '1') return true;
        if (valor === false || valor === 0 || valor === '0') return false;
        return null;
    }

    // ---------------------------------------------------------------------
    // Comparação fonte (API do ToolboxWaze) × Place no WME
    // Resultado é apenas auxiliar: nome parecido não prova que é o mesmo local.
    // ---------------------------------------------------------------------

    // COMPARACAO_INICIO
    const ABREVIACOES_ENDERECO = {
        r: 'rua', av: 'avenida', avda: 'avenida', pc: 'praca', pca: 'praca',
        trav: 'travessa', tv: 'travessa', rod: 'rodovia', estr: 'estrada',
        al: 'alameda', lgo: 'largo', lrg: 'largo', vl: 'vila', jd: 'jardim',
        pq: 'parque', dr: 'doutor', prof: 'professor', profa: 'professora',
        cel: 'coronel', gen: 'general', sta: 'santa', sto: 'santo'
    };

    const PALAVRAS_VAZIAS = new Set(['de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o']);

    const RESULTADOS = {
        igual: { texto: 'Igual após normalização', classe: 'igual' },
        parecido: { texto: 'Parecido · conferir', classe: 'parecido' },
        diferente: { texto: 'Diferente', classe: 'diferente' },
        ausente_fonte: { texto: 'Ausente na fonte', classe: 'ausente' },
        ausente_wme: { texto: 'Ausente no WME', classe: 'ausente' },
        nao_comparavel: { texto: 'Não comparável', classe: 'info' },
        conferir: { texto: 'Conferir', classe: 'parecido' },
        informativo: { texto: 'Apenas informativo', classe: 'info' }
    };

    function semAcento(texto) {
        return String(texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function normalizarTexto(texto) {
        return semAcento(texto)
            .toLowerCase()
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function tokensTexto(texto, expandir) {
        return normalizarTexto(texto)
            .split(' ')
            .filter(Boolean)
            .map(token => (expandir && ABREVIACOES_ENDERECO[token]) || token)
            .filter(token => !PALAVRAS_VAZIAS.has(token));
    }

    function similaridade(a, b) {
        const conjuntoA = new Set(a);
        const conjuntoB = new Set(b);
        if (!conjuntoA.size || !conjuntoB.size) return 0;
        let comuns = 0;
        conjuntoA.forEach(token => { if (conjuntoB.has(token)) comuns++; });
        return comuns / (conjuntoA.size + conjuntoB.size - comuns);
    }

    function compararTextos(fonte, wme, opcoes = {}) {
        const f = textoLimitado(fonte, 500);
        const w = textoLimitado(wme, 500);
        if (!f && !w) return { resultado: 'nao_comparavel', detalhe: 'Sem dado nos dois lados.' };
        if (!f) return { resultado: 'ausente_fonte' };
        if (!w) return { resultado: 'ausente_wme' };

        const tf = tokensTexto(f, opcoes.expandir);
        const tw = tokensTexto(w, opcoes.expandir);
        if (!tf.length || !tw.length) return { resultado: 'nao_comparavel', detalhe: 'Texto sem conteúdo comparável.' };
        if (tf.join(' ') === tw.join(' ')) return { resultado: 'igual' };

        const contido = tf.length >= 2 && tw.length >= 2
            && (tf.every(t => tw.includes(t)) || tw.every(t => tf.includes(t)));

        if (contido || similaridade(tf, tw) >= 0.6) {
            return { resultado: 'parecido', detalhe: 'Textos parecidos, não idênticos: confirme manualmente.' };
        }

        return { resultado: 'diferente' };
    }

    function extrairTelefones(valor) {
        const bruto = textoLimitado(valor, 200);
        if (!bruto) return [];

        return bruto
            .split(/[\/;,|]|\se\s|\sou\s/i)
            .map(parte => {
                let digitos = parte.replace(/\D/g, '');
                if (digitos.length >= 12 && digitos.startsWith('55')) digitos = digitos.slice(2);
                if (digitos.length >= 11 && digitos.startsWith('0')) digitos = digitos.replace(/^0+/, '');
                return digitos;
            })
            .filter(digitos => digitos.length >= 8);
    }

    function telefonesCorrespondem(a, b) {
        if (a === b) return 'exato';
        const [curto, longo] = a.length <= b.length ? [a, b] : [b, a];
        if (curto.length >= 8 && longo.endsWith(curto)) return 'sufixo';
        return null;
    }

    function normalizarNumero(valor) {
        const texto = textoLimitado(valor, 50);
        if (!texto) return null;
        const limpo = semAcento(texto).toLowerCase().replace(/[^a-z0-9/]/g, '');
        if (!limpo || /^s\/?n$/.test(limpo)) return null;
        return limpo;
    }

    /** Separa rua e número de um endereço de fonte como "RUA A, 120, CENTRO". */
    function dividirEnderecoFonte(texto) {
        const bruto = textoLimitado(texto, 500);
        if (!bruto) return null;

        const semBairro = parte => String(parte).split(/\s[-–]\s/)[0].trim();
        const partes = bruto.split(',').map(p => p.trim()).filter(Boolean);
        let rua = semBairro(partes[0] || '');
        let numero = null;

        const embutido = rua.match(/^(.*\D)\s+(\d{1,5}[A-Za-z]?)$/);

        if (embutido) {
            rua = embutido[1].trim();
            numero = embutido[2];
        } else {
            const candidato = partes
                .slice(1, 3)
                .map(semBairro)
                .find(p => /^(\d{1,5}[A-Za-z]?|s\/?n)$/i.test(p));
            if (candidato) numero = candidato;
        }

        return rua ? { rua, numero } : null;
    }

    function normalizarNomeValidacao(valor) {
        return semAcento(String(valor || '')).toLowerCase()
            .replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function nomeTitulo(valor) {
        const siglas = new Set(['UBS','UPA','SUS','SAMU','APAE','CMEI','EMEI','ESF','PSF']);
        return String(valor || '').trim().replace(/\s+/g, ' ').split(' ').map((p,i) => {
            if (siglas.has(p.toUpperCase())) return p.toUpperCase();
            p=p.toLocaleLowerCase('pt-BR');
            return i && /^(de|da|do|das|dos|e|em|na|no)$/.test(p) ? p :
                p.replace(/(^|[-'])\p{L}/gu,c=>c.toLocaleUpperCase('pt-BR'));
        }).join(' ');
    }

    function compararNomesValidacao(a,b) {
        if (!String(a || '').trim() && !String(b || '').trim()) return {resultado:'nao_comparavel'};
        if (!String(a || '').trim()) return {resultado:'ausente_fonte'};
        if (!String(b || '').trim()) return {resultado:'ausente_wme'};
        if (normalizarNomeValidacao(a) === normalizarNomeValidacao(b)) return {resultado:'igual'};
        const r=compararTextos(a,b);
        return {resultado:r.resultado === 'igual' ? 'parecido' : r.resultado,
            detalhe:'Texto diferente após normalização. Confira a identidade do estabelecimento.'};
    }

    function telefoneBR(valor) {
        let n=String(valor || '').replace(/\D/g,'').replace(/^0+/, '');
        if (/^55\d{10,11}$/.test(n)) n=n.slice(2);
        return n;
    }

    function listaTelefonesValidacao(valor) {
        return [...new Set(String(valor || '').split(/[\/;,|]|\s+e\s+|\s+ou\s+/i)
            .map(telefoneBR).filter(n=>/^\d{8,11}$/.test(n)))].sort();
    }

    function compararTelefones(fonte,wme) {
        const f=listaTelefonesValidacao(fonte), w=listaTelefonesValidacao(wme);
        if (!f.length && !w.length) return {resultado:'nao_comparavel'};
        if (!f.length) return {resultado: String(fonte || '').trim() ? 'nao_comparavel' : 'ausente_fonte', detalhe:'Telefone da fonte ausente ou inválido.'};
        if (!w.length) return {resultado:'ausente_wme'};
        if (JSON.stringify(f) === JSON.stringify(w)) return {resultado:'igual'};
        const parcial=f.some(a=>w.some(b=>a!==b && (a.length===8 || a.length===9 || b.length===8 || b.length===9) && (a.endsWith(b)||b.endsWith(a))));
        return {resultado:parcial?'parecido':'diferente', detalhe:parcial?'Número local coincide, mas o DDD ou a lista de números precisa ser conferida.':'Números diferentes após remover formatação, prefixo zero e código +55.'};
    }

    function numeroValidacao(valor) {
        let n=normalizarNumero(valor);
        return n ? n.replace(/^0+(?=\d)/,'') : null;
    }

    function enderecoValidacao(valor) {
        const texto=String(valor || '').trim();
        const m=texto.match(/^(.*?)\s*[-–—]\s*(\d{1,6}[a-z]?)\s*(?:[,;].*)?$/i);
        if (m) return {rua:m[1].trim(),numero:m[2]};
        return dividirEnderecoFonte(texto);
    }

    function ruaValidacao(valor) {
        return normalizarNomeValidacao(valor).split(' ').map(t=>ABREVIACOES_ENDERECO[t] || t).join(' ');
    }

    function compararEndereco(item,venue) {
        const f=enderecoValidacao(item.endereco), w=venue.enderecoSdk || {};
        if (!f && !w.rua) return {resultado:'nao_comparavel'};
        if (!f) return {resultado:'ausente_fonte'};
        if (!w.rua) return {resultado:'ausente_wme'};
        if (ruaValidacao(f.rua)!==ruaValidacao(w.rua)) {
            const r=compararTextos(f.rua,w.rua,{expandir:true});
            return {resultado:r.resultado==='diferente'?'diferente':'parecido',detalhe:'Nomes de rua não coincidem após normalização.'};
        }
        const nf=numeroValidacao(f.numero), nw=numeroValidacao(w.numero);
        if (nf && nw && nf!==nw) return {resultado:'diferente',detalhe:`Número: ${f.numero} na fonte × ${w.numero} no WME.`};
        if (nf && !nw) return {resultado:'ausente_wme',detalhe:'Número informado na fonte e ausente no WME.'};
        if (!nf && nw) return {resultado:'conferir',detalhe:'Rua coincide, mas falta número na fonte; não sobrescrever automaticamente.'};
        return {resultado:'igual'};
    }


    function linhaComparacao(campo, fonte, wme, resultado) {
        return {
            campo,
            fonte: textoLimitado(fonte, 300),
            wme: textoLimitado(wme, 300),
            resultado: resultado.resultado,
            detalhe: resultado.detalhe || ''
        };
    }

    function montarComparacao(item, venue) {
        const escola = item.tipo === 'ESCOLAS';
        const nomeFonte = escola ? item.escola : item.nome;
        const cidadeWme = venue.enderecoSdk ? venue.enderecoSdk.cidade : null;
        const categoriaFonte = escola
            ? ['Escola', item.dependenciaAdministrativa || item.categoriaAdministrativa].filter(Boolean).join(' · ')
            : item.categoria;
        const categoriaWme = (venue.categoriasNomes || venue.categorias || []).join(', ');
        const situacaoFonte = escola ? item.situacaoFuncionamento : item.situacao;

        // Categoria não é comparável automaticamente: só vira alerta se o Place não tiver nenhuma.
        let categoria = { resultado: 'conferir' };
        if (!textoLimitado(categoriaWme, 300)) categoria = { resultado: 'ausente_wme' };

        return [
            linhaComparacao('Nome', nomeFonte, venue.nome, compararNomesValidacao(nomeFonte, venue.nome)),
            linhaComparacao('Telefone', item.telefone, venue.telefone, compararTelefones(item.telefone, venue.telefone)),
            linhaComparacao('Endereço', item.endereco, venue.endereco, compararEndereco(item, venue)),
            linhaComparacao('Município', item.municipio, cidadeWme, compararTextos(item.municipio, cidadeWme)),
            linhaComparacao('Categoria', categoriaFonte, categoriaWme, categoria),
            linhaComparacao('Situação', situacaoFonte || 'Não informado', null, { resultado: 'informativo' })
        ];
    }
    // COMPARACAO_FIM

    function htmlLinhaComparacao(linha) {
        const definicao = RESULTADOS[linha.resultado] || RESULTADOS.nao_comparavel;
        const textoIdentico = linha.fonte && linha.wme
            && linha.fonte.trim().toLowerCase() === linha.wme.trim().toLowerCase();
        const rotulo = linha.resultado === 'igual' && textoIdentico ? 'Igual' : definicao.texto;

        return '<div class="wmt-cmp-linha">' +
            `<div class="wmt-cmp-topo"><span>${esc(linha.campo)}</span>` +
            `<span class="wmt-chip wmt-chip-${definicao.classe}">${esc(rotulo)}</span></div>` +
            `<small>Fonte: ${esc(linha.fonte || '—')}</small>` +
            `<small>WME: ${esc(linha.wme || '—')}</small>` +
            (linha.detalhe ? `<small class="wmt-cmp-detalhe">${esc(linha.detalhe)}</small>` : '') +
            '</div>';
    }

    function htmlLinhaCompacta(linha) {
        let valor = linha.fonte || '—';

        if (linha.resultado === 'informativo') {
            valor += ' · apenas informativo';
        } else if (linha.resultado === 'nao_comparavel') {
            valor = linha.detalhe || 'não comparável';
        } else if (linha.resultado === 'conferir') {
            valor = `${linha.fonte || 'sem dado na fonte'} × ${linha.wme || '—'} (conferir)`;
        }

        return `<div class="wmt-cmp-compacta"><b>${esc(linha.campo)}:</b><span>${esc(valor)}</span></div>`;
    }

    function linhasValidacao(item,venue) {
        return montarComparacao(item,venue).filter(l=>!['Categoria','Situação'].includes(l.campo)
            && ['diferente','parecido','ausente_wme','conferir'].includes(l.resultado)
            && l.fonte);
    }

    function htmlComparacao(item,venue) {
        const linhas=linhasValidacao(item,venue);
        return '<div class="wmt-cmp"><div class="wmt-cmp-titulo">Validação fonte × WME</div>' +
            (linhas.length ? linhas.map(htmlLinhaComparacao).join('') +
            '<button type="button" class="wmt-primario wmt-full" data-validacao-copy>Copiar diferenças</button>' :
            '<p>Sem divergências nos dados comparáveis. Campos ausentes ou inválidos não confirmam igualdade.</p>') +
            '<small>Dados semelhantes ou incompletos exigem revisão. Copiar não altera o mapa.</small></div>';
    }


    function apiVenuesSdk() {
        const api = state.sdk?.DataModel?.Venues;
        if (!api || typeof api.getById !== 'function') {
            throw new Error('SDK de Places indisponível. Atualize o WME; o script não usa o modelo interno legado.');
        }
        return api;
    }

    function idsSelecionadosSdk() {
        if (typeof state.sdk?.Editing?.getSelection !== 'function') {
            throw new Error('O SDK não disponibilizou a leitura da seleção.');
        }
        const selection = state.sdk.Editing.getSelection();
        if (!selection || String(selection.objectType).toLowerCase() !== 'venue') return [];
        return Array.from(new Set((selection.ids || []).map(String)));
    }

    function coordenadasVenue(venue) {
        const geometry = venue.geometry;
        if (geometry?.type === 'Point' && Array.isArray(geometry.coordinates)) {
            const [lon, lat] = geometry.coordinates.map(Number);
            if (Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180) return {lat, lon};
        }
        return null;
    }

    function permalinkVenue(id, coords) {
        const url = new URL('https://www.waze.com/editor');
        url.searchParams.set('env', 'row');
        url.searchParams.set('venues', String(id));
        if (coords) {
            url.searchParams.set('lat', String(coords.lat));
            url.searchParams.set('lon', String(coords.lon));
            url.searchParams.set('zoomLevel', '19');
        }
        return url.href;
    }

    async function resumirVenue(venue, id) {
        const api = apiVenuesSdk();
        let endereco = null, enderecoSdk = null;
        try {
            const a = await Promise.resolve(api.getAddress({venueId: String(id)}));
            if (a && !a.isEmpty) {
                const nomeEndereco = part => textoLimitado(typeof part === 'object' ? part?.name : part, 255);
                enderecoSdk = {
                    rua: nomeEndereco(a.street), numero: textoLimitado(a.houseNumber, 50),
                    cidade: nomeEndereco(a.city), estado: nomeEndereco(a.state), pais: nomeEndereco(a.country)
                };
                endereco = textoLimitado([enderecoSdk.rua, enderecoSdk.numero, enderecoSdk.cidade, enderecoSdk.estado]
                    .filter(Boolean).join(', '), 500);
            }
        } catch (erro) { /* Endereço opcional: não transforma falha de endereço em venue ausente. */ }
        let labels = new Map();
        try {
            if (typeof api.getAllVenueCategories === 'function') {
                const categories = await Promise.resolve(api.getAllVenueCategories());
                labels = new Map((Array.isArray(categories) ? categories : []).map(c => [String(c.id), String(c.localizedName || c.id)]));
            }
        } catch (erro) { /* Mantém os códigos documentados das categorias. */ }
        const categorias = Array.isArray(venue.categories) ? venue.categories.slice(0, 20).map(String) : [];
        return {
            id: String(venue.id ?? id), nome: textoLimitado(venue.name, 255), categorias,
            categoriasNomes: categorias.map(code => labels.get(code) || code),
            telefone: textoLimitado(venue.phone, 50), url: textoLimitado(venue.url, 500),
            endereco, enderecoSdk, coordenadas: coordenadasVenue(venue),
            aprovado: typeof venue.approved === 'boolean' ? venue.approved : null,
            origem: 'WME_SDK', capturadoEm: new Date().toISOString()
        };
    }

    async function lerVenue(id) {
        const api = apiVenuesSdk();
        let venue;
        try { venue = await Promise.resolve(api.getById({venueId: String(id)})); }
        catch (erro) {
            if (/notfound|not found|não encontrado/i.test(String(erro?.name) + ' ' + String(erro?.message))) return null;
            throw new Error('Falha ao ler o Place pelo SDK: ' + (erro?.message || erro));
        }
        if (!venue) return null;
        if (String(venue.id) !== String(id)) throw new Error('O SDK retornou um Place com ID diferente do solicitado.');
        try {
            if (typeof state.sdk.DataModel.isDeleted === 'function' && state.sdk.DataModel.isDeleted({dataModelName:'venues', objectId:String(id)})) return null;
        } catch (erro) { /* A leitura acima continua sendo a fonte do objeto. */ }
        return resumirVenue(venue, String(id));
    }

    async function aguardarVenues(ids, sequencia) {
        const inicio = Date.now();
        const achados = new Map();
        while (sequencia === state.venueSeq) {
            const results = await Promise.all(ids.filter(id => !achados.has(id)).map(async id => [id, await lerVenue(id)]));
            if (sequencia !== state.venueSeq) break;
            results.forEach(([id, dados]) => { if (dados) achados.set(id, dados); });
            if (achados.size === ids.length || Date.now()-inicio >= VENUE.ESPERA_MS) break;
            await new Promise(resolve => setTimeout(resolve, VENUE.INTERVALO_MS));
        }
        return {encontrados: ids.filter(id => achados.has(id)).map(id => achados.get(id)), ausentes: ids.filter(id => !achados.has(id))};
    }

    function prepararVenues(item) {
        const sequencia = ++state.venueSeq;
        if (!item || !['ESCOLAS','SAUDE'].includes(item.tipo)) { renderizarVenues(null); return; }
        const ids = Array.from(new Set([...extrairVenueIds(item.linkWaze), ...extrairVenueIds(item.linkAreaEscolar)]));
        item.venues = {ids, encontrados:[], ausentes:[], escolha:undefined, carregando:ids.length>0,
            promessa:null, erro:null, extras:[], registrarLink:false, selecaoManual:false};
        renderizarVenues(item);
        if (!ids.length) return;
        item.venues.promessa = aguardarVenues(ids, sequencia).then(result => {
            if (sequencia !== state.venueSeq || state.lote[state.indice] !== item) return;
            Object.assign(item.venues, result, {carregando:false});
            if (ids.length === 1 && result.encontrados.length === 1) item.venues.escolha = result.encontrados[0].id;
            renderizarVenues(item);
        }).catch(erro => {
            if (sequencia !== state.venueSeq || state.lote[state.indice] !== item) return;
            item.venues.carregando=false; item.venues.erro=erro.message; renderizarVenues(item);
        });
    }

    async function atualizarVenuesAtual(usarSelecao = false) {
        if (state.salvando || state.carregando) return;
        const item = state.lote[state.indice];
        if (!item?.venues) return;
        const sequencia = ++state.venueSeq;
        const venues = item.venues;
        venues.erro = null;
        try {
            let selected = [];
            if (usarSelecao) {
                selected = idsSelecionadosSdk();
                if (!selected.length) throw new Error('Selecione um Place no mapa do WME (não um segmento ou hazard).');
                venues.extras = Array.from(new Set([...venues.extras, ...selected]));
                venues.selecaoManual = true;
            }
            venues.ids = Array.from(new Set([...extrairVenueIds(item.linkWaze), ...extrairVenueIds(item.linkAreaEscolar), ...venues.extras]));
            // Releitura disparada por eventos do mapa: se já há Places lidos, não troca o
            // bloco por "Aguardando…" (evita o painel piscar e perder a seleção de texto).
            const segundoPlano = !usarSelecao && venues.encontrados.length > 0;
            if (!segundoPlano) { venues.carregando = true; renderizarVenues(item); }
            venues.promessa = aguardarVenues(venues.ids, sequencia);
            const resultado = await venues.promessa;
            if (sequencia !== state.venueSeq || state.lote[state.indice] !== item) return;
            Object.assign(venues, resultado, {carregando:false, promessa:null});
            if (selected.length === 1 && resultado.encontrados.some(v => v.id === selected[0])) {
                venues.escolha = selected[0];
                // Place escolhido no mapa e ainda não vinculado: já marca o registro do link
                // (o resumo de confirmação continua aparecendo antes de enviar).
                if (!idsDosLinks(item).includes(selected[0])) venues.registrarLink = true;
            }
            else if (!usarSelecao && venues.escolha === undefined && venues.ids.length === 1 && resultado.encontrados.length === 1) venues.escolha = resultado.encontrados[0].id;
            if (venues.escolha !== VENUE.NENHUM && !resultado.encontrados.some(v => v.id === venues.escolha)) venues.escolha = undefined;
            renderizarVenues(item);
        } catch (erro) {
            if (sequencia !== state.venueSeq || state.lote[state.indice] !== item) return;
            venues.carregando=false; venues.promessa=null; venues.erro=erro.message; renderizarVenues(item);
        }
    }

    function renderizarVenues(item) {
        const elemento = ui('wmt-venue');
        if (!elemento) return;
        const venues = item?.venues;
        if (!venues) { elemento.style.display='none'; elemento.innerHTML=''; return; }
        const disabled = state.salvando || venues.carregando;
        let html = '<b>📍 Place no Waze</b>';
        html += '<div class="wmt-row" style="margin-top:8px">' +
            `<button type="button" class="wmt-neutro wmt-btn-mini" data-venue-action="selecao" ${disabled?'disabled':''}>Usar Place selecionado</button>` +
            `<button type="button" class="wmt-neutro wmt-btn-mini" data-venue-action="atualizar" ${disabled?'disabled':''}>Reler dados</button></div>`;
        if (venues.erro) html += `<div role="alert" class="wmt-erro-texto">${esc(venues.erro)}</div>`;
        if (venues.carregando) html += '<p role="status">Aguardando o Place carregar no modelo do WME…</p>';
        else if (!venues.ids.length) html += '<p>Sem Place vinculado. <b>Clique no Place correto no mapa</b>: ele é usado automaticamente. (Um Permanent Hazard não é um Place.)</p>';
        else if (!venues.encontrados.length) html += `<p>Place ainda não carregado no mapa. Isso não prova que ele não exista. Aguarde o mapa carregar, clique no Place ou use “Reler dados”.</p>`;
        venues.encontrados.forEach(venue => {
            const labels = venue.categoriasNomes || venue.categorias || [];
            html += `<label class="wmt-venue-opcao"><input type="radio" name="wmt-venue-escolha" value="${esc(venue.id)}" ${venues.escolha===venue.id?'checked':''} ${disabled?'disabled':''}><span>` +
                `<b>${esc(venue.nome || 'Place sem nome')}</b><small>ID: ${esc(venue.id)}</small>` +
                (labels.length ? `<small>${esc(labels.join(', '))}</small>` : '') +
                (venue.endereco ? `<small>${esc(venue.endereco)}</small>` : '') +
                `<small>Telefone no WME: ${esc(venue.telefone || 'Não informado')}</small>` +
                (venue.url ? `<small>Site no WME: ${esc(venue.url)}</small>` : '') +
                (venue.aprovado === false ? '<small class="wmt-aviso-texto">Place sugerido / ainda não aprovado</small>' : '') +
                '</span></label>';
        });
        if (venues.encontrados.length) {
            html += `<label class="wmt-venue-opcao"><input type="radio" name="wmt-venue-escolha" value="${VENUE.NENHUM}" ${venues.escolha===VENUE.NENHUM?'checked':''} ${disabled?'disabled':''}><span>Não incluir dados de Place nesta confirmação</span></label>`;
        }
        if (venues.ausentes.length) html += `<small>IDs não carregados: ${esc(venues.ausentes.join(', '))}. Nenhum local próximo é associado automaticamente.</small>`;

        const escolhido = venues.encontrados.find(v => v.id === venues.escolha);
        if (escolhido) {
            html += htmlComparacao(item, escolhido);
        } else if (venues.encontrados.length && venues.escolha !== VENUE.NENHUM) {
            html += '<small>Escolha um Place para ver a comparação com a fonte.</small>';
        }

        if (venues.encontrados.length) {
            html += `<label style="display:flex;gap:6px;align-items:start;margin-top:10px"><input type="checkbox" data-venue-register-link ${venues.registrarLink?'checked':''} ${disabled?'disabled':''}><span>Registrar o link deste Place no ToolboxWaze ao confirmar (${item.tipo==='ESCOLAS'?'campo Place escolar; preserva o hazard':'campo link Waze'}).</span></label>`;
            html += '<small>O script não altera o mapa nem salva edições do WME. O snapshot do Place vai junto com a confirmação.</small>';
        }
        elemento.innerHTML=html; elemento.style.display='block';
    }

    async function obterVenueEscolhido(item) {
        const venues = item.venues;
        if (!venues) return null;
        if (venues.promessa) { try { await venues.promessa; } catch (erro) { /* Erro exibido no painel. */ } }
        if (state.lote[state.indice] !== item) throw new Error('A tarefa mudou durante a leitura do Place.');
        if (venues.escolha === VENUE.NENHUM) return null;
        if (venues.erro) throw new Error(venues.erro + ' Releia o Place ou escolha explicitamente não incluir seus dados.');
        if (!venues.encontrados.length) return null;
        if (!venues.escolha) throw new Error('Escolha o Place correto ou “Não incluir dados de Place”.');
        const id = String(venues.escolha);
        const model = state.sdk.DataModel;
        if (/^-/.test(id) || (typeof model.isNew==='function' && model.isNew({dataModelName:'venues',objectId:id}))) {
            throw new Error('O Place ainda é novo no WME. Salve as edições, selecione o Place salvo e releia pelo SDK.');
        }
        if (typeof state.sdk.Editing?.getUnsavedChangesCount==='function' && state.sdk.Editing.getUnsavedChangesCount()>0) {
            throw new Error('Há edições não salvas no WME. Salve ou descarte-as antes de enviar o snapshot, para não confirmar dados ainda locais.');
        }
        const atual = await lerVenue(id);
        if (!atual) throw new Error('O Place escolhido deixou de estar disponível no modelo. Recarregue os dados antes de confirmar.');
        if (atual.aprovado === false) throw new Error('Este Place ainda é uma sugestão não aprovada. Não será enviado como Place confirmado.');
        venues.encontrados = venues.encontrados.map(v => v.id===id ? atual : v);
        return atual;
    }

    // ---------------------------------------------------------------------
    // Vínculo (permalink do Place) e resumo de confirmação
    // ---------------------------------------------------------------------

    function coordenadasParaLink(item, venue) {
        if (venue.coordenadas) return venue.coordenadas;

        if (item.coords) {
            const [lat, lon] = String(item.coords).split(',').map(Number);

            if (Number.isFinite(lat) && Number.isFinite(lon)) {
                return { lat, lon };
            }
        }

        return null;
    }

    /**
     * Decide, sem gravar nada, o que acontecerá com o vínculo no ToolboxWaze.
     * acao: nenhum | gravar | substituir | ja_registrado
     */
    function planejarVinculo(item, venue) {
        if (!item.venues || !item.venues.registrarLink || !venue) {
            return { acao: 'nenhum' };
        }

        const campo = item.tipo === 'ESCOLAS' ? 'link_area_escolar' : 'link_waze';
        const atual = campo === 'link_area_escolar' ? item.linkAreaEscolar : item.linkWaze;
        const link = permalinkVenue(venue.id, coordenadasParaLink(item, venue));

        if (atual && extrairVenueIds(atual).includes(String(venue.id))) {
            return { acao: 'ja_registrado', campo, atual, link };
        }

        return { acao: atual ? 'substituir' : 'gravar', campo, atual, link };
    }

    function idsDosLinks(item) {
        return Array.from(new Set([
            ...extrairVenueIds(item.linkWaze),
            ...extrairVenueIds(item.linkAreaEscolar)
        ]));
    }

    /** Motivos pelos quais o resumo de confirmação deve aparecer. Vazio = enviar direto. */
    function motivosResumo(item, venue) {
        if (!venue) return [];

        const motivos = [];
        const plano = planejarVinculo(item, venue);
        const doLink = idsDosLinks(item);

        if (plano.acao === 'substituir') {
            motivos.push('O link já cadastrado no ToolboxWaze será substituído pelo permalink deste Place.');
        }

        if (item.venues && item.venues.selecaoManual && !doLink.includes(String(venue.id))) {
            motivos.push('Place escolhido manualmente: ele não consta nos links cadastrados.');
        }

        if (doLink.length > 1) {
            motivos.push(`Os links cadastrados apontam para ${doLink.length} Places diferentes.`);
        }

        montarComparacao(item, venue)
            .filter(linha => (linha.campo === 'Nome' || linha.campo === 'Endereço') && linha.resultado === 'diferente')
            .forEach(linha => {
                motivos.push(
                    `${linha.campo} diferente entre a fonte e o WME` +
                    (linha.detalhe ? ` (${linha.detalhe.replace(/\.$/, '')})` : '') + '.'
                );
            });

        return motivos;
    }

    function montarResumo(item, venue, motivos) {
        const nome = nomeDaTarefa(item);
        const codigo = item.tipo === 'ESCOLAS'
            ? (item.codigoInep ? `INEP ${item.codigoInep}` : '')
            : (item.cnes ? `CNES ${item.cnes}` : '');
        const plano = planejarVinculo(item, venue);
        const campoRotulo = item.tipo === 'ESCOLAS' ? 'campo Place escolar' : 'campo link Waze';
        const itens = ['Será salvo o status <b>confirmado</b>, junto com o snapshot dos dados do Place.'];

        if (plano.acao === 'gravar' || plano.acao === 'substituir') {
            itens.push(`O permalink do Place será registrado no ToolboxWaze (${esc(campoRotulo)}).`);
        } else if (plano.acao === 'ja_registrado') {
            itens.push('O vínculo cadastrado já aponta para este Place e não será alterado.');
        } else {
            itens.push('Nenhum link do ToolboxWaze será alterado.');
        }

        itens.push('O telefone e os demais dados do Place no WME não serão alterados.');

        return {
            titulo: 'Confirmar vínculo',
            paragrafosHtml: [
                `Você está confirmando <b>${esc(nome)}</b>${codigo ? ` (${esc(codigo)})` : ''} ` +
                `com o Place <b>${esc(venue.nome || 'sem nome')}</b> (ID ${esc(venue.id)}).`
            ],
            itensHtml: itens,
            motivos
        };
    }

    /** Grava o vínculo no ToolboxWaze quando autorizado. Nunca altera o WME. */
    async function registrarLinkVenueSeAutorizado(item, venue, api) {
        if (item.venues && item.venues.registrarLink && !venue) {
            throw new Error('Escolha um Place para registrar seu permalink ou desmarque a opção de registro.');
        }

        const plano = planejarVinculo(item, venue);

        if (plano.acao === 'nenhum') {
            return { salvo: false, detalhe: 'Não autorizado: nenhum link foi alterado.' };
        }

        if (plano.acao === 'ja_registrado') {
            return { salvo: false, detalhe: 'O vínculo cadastrado já aponta para este Place; nada a gravar.' };
        }

        await chamarApi('PATCH', `/api/${api}/${item.id}`, { [plano.campo]: plano.link });

        if (plano.campo === 'link_area_escolar') {
            item.linkAreaEscolar = plano.link;
        } else {
            item.linkWaze = plano.link;
        }

        // Se o PATCH de status falhar depois, a alteração de link já foi salva.
        const cache = lerCache();

        if (cache && Array.isArray(cache.itens)) {
            const registro = cache.itens.find(r => String(r.id) === String(item.id));

            if (registro) {
                registro[plano.campo] = plano.link;
            }

            store.setJson(KEYS.CACHE, cache);
        }

        renderizarInfoTarefa(item);

        return {
            salvo: true,
            detalhe: plano.acao === 'substituir' ? 'Vínculo substituído.' : 'Vínculo registrado.'
        };
    }

    /** Selecionou um Place no mapa: usa-o automaticamente na tarefa atual (sem clicar em botão). */
    function aoMudarSelecao() {
        clearTimeout(state.selecaoTimer);

        state.selecaoTimer = setTimeout(() => {
            const item = state.lote[state.indice];

            if (!item || !item.venues || state.salvando || state.carregando) {
                return;
            }

            let ids = [];

            try {
                ids = idsSelecionadosSdk();
            } catch (erro) {
                return;
            }

            if (ids.length !== 1 || item.venues.escolha === ids[0]) {
                return;
            }

            atualizarVenuesAtual(true);
        }, 250);
    }

    function inicializarLeituraVenuesSdk() {
        if (state.venueEventsBound || !state.sdk?.Events?.on) return;
        state.venueEventsBound=true;
        const atualizar = () => {
            const item=state.lote[state.indice];
            if (!item?.venues || item.venues.carregando || state.salvando || state.carregando) return;
            clearTimeout(state.venueRefreshTimer);
            state.venueRefreshTimer=setTimeout(() => atualizarVenuesAtual(false),350);
        };
        try { state.sdk.Events.on({eventName:'wme-map-data-loaded',eventHandler:atualizar}); } catch (erro) { /* Botão de releitura continua disponível. */ }
        try { state.sdk.Events.on({eventName:'wme-selection-changed',eventHandler:aoMudarSelecao}); } catch (erro) { /* Botão "Usar Place selecionado" continua disponível. */ }
        try {
            state.sdk.Events.trackDataModelEvents({dataModelName:'venues'});
            ['wme-data-model-objects-changed','wme-data-model-objects-added','wme-data-model-objects-saved','wme-data-model-objects-removed','wme-data-model-object-changed-id'].forEach(eventName => {
                state.sdk.Events.on({eventName,eventHandler:event => { if (event?.dataModelName==='venues') atualizar(); }});
            });
        } catch (erro) { /* Compatibilidade: polling inicial e botão manual não dependem dos eventos. */ }
    }

    // ---------------------------------------------------------------------
    // Etapas do envio (só aparecem durante o envio e quando há falha)
    // ---------------------------------------------------------------------

    function iniciarEtapas(titulo, lista) {
        state.etapas = {
            titulo,
            retry: false,
            itens: lista.map(([id, rotulo]) => ({
                id,
                rotulo,
                estado: 'pendente',
                detalhe: ''
            }))
        };

        renderizarEtapas();
    }

    function marcarEtapa(id, estado, detalhe) {
        if (!state.etapas) {
            return;
        }

        const etapa = state.etapas.itens.find(e => e.id === id);

        if (!etapa) {
            return;
        }

        etapa.estado = estado;

        if (detalhe !== undefined) {
            etapa.detalhe = detalhe;
        }

        renderizarEtapas();
    }

    function definirTituloEtapas(titulo, retry) {
        if (!state.etapas) {
            return;
        }

        state.etapas.titulo = titulo;
        state.etapas.retry = Boolean(retry);
        renderizarEtapas();
    }

    function limparEtapas() {
        state.etapas = null;
        renderizarEtapas();
    }

    function reiniciarEtapasStatus(titulo) {
        if (!state.etapas) {
            iniciarEtapas(titulo, [
                ['status', 'Salvando status'],
                ['conferir', 'Conferindo a resposta do servidor']
            ]);
            return;
        }

        state.etapas.titulo = titulo;
        state.etapas.retry = false;

        state.etapas.itens.forEach(etapa => {
            if (['status', 'conferir'].includes(etapa.id)) {
                etapa.estado = 'pendente';
                etapa.detalhe = '';
            }
        });

        renderizarEtapas();
    }

    function renderizarEtapas() {
        const elemento = ui('wmt-etapas');

        if (!elemento) {
            return;
        }

        const etapas = state.etapas;

        if (!etapas) {
            elemento.style.display = 'none';
            elemento.innerHTML = '';
            return;
        }

        elemento.innerHTML =
            '<div class="wmt-etapas-topo">' +
            `<div class="wmt-etapas-titulo">${esc(etapas.titulo)}</div>` +
            '<button type="button" class="wmt-etapas-fechar" data-etapas-acao="fechar" title="Ocultar" aria-label="Ocultar etapas">✕</button>' +
            '</div>' +
            etapas.itens.map(etapa => (
                `<div class="wmt-etapa wmt-etapa-${etapa.estado}">` +
                `<span class="wmt-etapa-icone">${ETAPA_ICONE[etapa.estado] || '○'}</span>` +
                `<span>${esc(etapa.rotulo)}` +
                (etapa.detalhe ? `<small>${esc(etapa.detalhe)}</small>` : '') +
                '</span></div>'
            )).join('') +
            (etapas.retry
                ? '<button type="button" class="wmt-aviso wmt-full" data-etapas-acao="retry-status">Tentar novamente somente o status</button>'
                : '');

        elemento.style.display = 'block';
    }

    function conferirRespostaStatus(resposta, statusEsperado, enviouSnapshot) {
        const registro = resposta && (resposta.escola || resposta.unidade);

        if (!registro || typeof registro !== 'object') {
            throw new Error(
                'A resposta do servidor não trouxe o registro atualizado; o status não pôde ser conferido.'
            );
        }

        if (registro.status !== statusEsperado) {
            const recebido = registro.status === null || registro.status === undefined
                ? 'vazio'
                : registro.status;

            throw new Error(
                `O servidor devolveu o status "${recebido}" em vez de "${statusEsperado}".`
            );
        }

        if (!enviouSnapshot) {
            return {
                snapshot: 'nao_enviado',
                detalhe: 'O servidor devolveu o status esperado.'
            };
        }

        const persistido = resposta.waze_persistido === true ||
            Boolean(resposta.waze && resposta.waze.persistido === true);

        return persistido
            ? {
                snapshot: 'confirmado',
                detalhe: 'Status e snapshot do Place confirmados pelo servidor.'
            }
            : {
                snapshot: 'sem_confirmacao',
                detalhe: 'Status confirmado. O snapshot do Place foi enviado, mas o servidor não confirmou o armazenamento.'
            };
    }

    /** PATCH do status + conferência da resposta. Erros saem marcados com etapaStatus. */
    async function concluirEnvioStatus(item, api, payload) {
        let etapa = 'status';

        try {
            marcarEtapa('status', 'andamento');

            const resposta = await chamarApi(
                'PATCH',
                `/api/${api}/${item.id}/status`,
                payload
            );

            marcarEtapa('status', 'ok');

            etapa = 'conferir';
            marcarEtapa('conferir', 'andamento');

            const conferencia = conferirRespostaStatus(
                resposta,
                payload.status,
                Boolean(payload.waze)
            );

            marcarEtapa(
                'conferir',
                'ok',
                conferencia.detalhe
            );

            removerDoCache(item);

            return conferencia;
        } catch (erro) {
            marcarEtapa(etapa, 'erro', erro.message);
            erro.etapaStatus = true;
            throw erro;
        }
    }

    /** Resultado do último envio: aparece no status da próxima tarefa (nada de etapas sobrando na tela). */
    function statusAposConferencia(conferencia) {
        const resultado = conferencia.snapshot === 'sem_confirmacao'
            ? { texto: 'Status confirmado. Snapshot sem confirmação de armazenamento.', nivel: 'aviso' }
            : { texto: 'Salvo e conferido no servidor.', nivel: 'ok' };

        state.ultimoResultado = resultado;
        setStatus(resultado.texto, resultado.nivel);
    }

    async function salvarTarefaApi(item, acao, usuario) {
        const api = item.tipo === 'ESCOLAS' ? 'escolas' : 'saude';

        const statusApi = {
            'RESOLVIDO': 'confirmado',
            'NÃO LOCALIZADO': 'nao_localizado',
            'PULADO': 'pulado'
        }[acao];

        if (!statusApi) {
            throw new Error(`Ação inválida para ${TIPOS[item.tipo].rotulo}.`);
        }

        const nome = nomeDaTarefa(item);
        const confirmando = statusApi === 'confirmado';
        const etapas = [];

        if (confirmando) {
            etapas.push(
                ['ler', 'Lendo novamente o Place'],
                ['validar', 'Validando a escolha'],
                ['vinculo', 'Salvando vínculo, se autorizado']
            );
        }

        etapas.push(
            ['status', 'Salvando status'],
            ['conferir', 'Conferindo a resposta do servidor']
        );

        iniciarEtapas(`Enviando · ${nome}`, etapas);

        const payload = {
            status: statusApi,
            username: usuario
        };

        let etapaAtual = confirmando ? 'ler' : 'status';
        let vinculoSalvo = false;

        try {
            if (confirmando) {
                marcarEtapa('ler', 'andamento');

                const waze = await obterVenueEscolhido(item);

                marcarEtapa(
                    'ler',
                    'ok',
                    waze
                        ? `Place ${waze.id} lido do modelo do WME.`
                        : 'Nenhum Place incluído nesta confirmação.'
                );

                etapaAtual = 'validar';
                marcarEtapa('validar', 'andamento');

                if (item.venues && item.venues.registrarLink && !waze) {
                    throw new Error(
                        'Escolha um Place para registrar seu permalink ou desmarque a opção de registro.'
                    );
                }

                const haVinculo = Boolean(
                    item.linkWaze ||
                    item.linkAreaEscolar ||
                    (item.venues && item.venues.registrarLink && waze)
                );

                if (!haVinculo) {
                    throw new Error(
                        'Sem vínculo: clique no Place correto no mapa (ele será usado e o link registrado) antes de confirmar.'
                    );
                }

                const motivos = motivosResumo(item, waze);

                if (motivos.length) {
                    marcarEtapa('validar', 'andamento', 'Aguardando sua confirmação no resumo…');

                    const confirmado = await confirmarResumo(
                        montarResumo(item, waze, motivos)
                    );

                    if (!confirmado) {
                        const cancelamento = new Error('Envio cancelado na revisão do resumo.');
                        cancelamento.cancelado = true;
                        throw cancelamento;
                    }
                }

                if (waze) {
                    payload.waze = waze;
                }

                marcarEtapa(
                    'validar',
                    'ok',
                    motivos.length ? 'Resumo confirmado por você.' : 'Sem alertas que exijam resumo.'
                );

                etapaAtual = 'vinculo';
                marcarEtapa('vinculo', 'andamento');

                const resultadoVinculo = await registrarLinkVenueSeAutorizado(item, waze, api);

                vinculoSalvo = resultadoVinculo.salvo;

                marcarEtapa(
                    'vinculo',
                    resultadoVinculo.salvo ? 'ok' : 'pulada',
                    resultadoVinculo.detalhe
                );
            }

            etapaAtual = 'status';

            return await concluirEnvioStatus(item, api, payload);
        } catch (erro) {
            if (erro && erro.etapaStatus) {
                item.pendencia = {
                    api,
                    payload,
                    vinculoSalvo,
                    nome
                };

                definirTituloEtapas(`Falha no envio · ${nome}`, true);
            } else {
                marcarEtapa(
                    etapaAtual,
                    'erro',
                    erro && erro.cancelado ? 'Cancelado por você.' : (erro && erro.message)
                );

                definirTituloEtapas(`Falha no envio · ${nome}`, false);
            }

            throw erro;
        }
    }

    async function tentarNovamenteStatus() {
        if (state.salvando || state.carregando) {
            return;
        }

        const item = state.lote[state.indice];
        const pendencia = item && item.pendencia;

        if (!pendencia) {
            return;
        }

        state.salvando = true;
        let avancar = false;

        bloquearControlesEnvio(true);
        desabilitarAcoes();
        registrarAtividade();
        renderizarVenues(item);
        setStatus('Reenviando somente o status...');
        reiniciarEtapasStatus(`Reenviando status · ${pendencia.nome}`);

        try {
            const conferencia = await concluirEnvioStatus(
                item,
                pendencia.api,
                pendencia.payload
            );

            item.pendencia = null;
            limparEtapas();
            statusAposConferencia(conferencia);
            avancar = true;
        } catch (erro) {
            definirTituloEtapas(`Falha no envio · ${pendencia.nome}`, true);

            setStatus(
                pendencia.vinculoSalvo
                    ? 'Vínculo salvo. Confirmação de status pendente.'
                    : 'Status ainda não confirmado: ' + erro.message,
                'erro'
            );

            habilitarAcoesDeEnvio();
        } finally {
            state.salvando = false;
            bloquearControlesEnvio(false);

            if (!avancar) {
                renderizarVenues(item);
            }
        }

        if (avancar) {
            irParaProxima();
        }
    }

    function criarCamadaMarcadores() {
        try {
            state.sdk.Map.addLayer({
                layerName: LAYER_NAME,
                styleRules: [{
                    style: {
                        externalGraphic: MARKER_ICON,
                        graphicWidth: 32,
                        graphicHeight: 32,
                        graphicXOffset: -16,
                        graphicYOffset: -32,
                        graphicOpacity: 1
                    }
                }]
            });
        } catch (erro) {
            console.warn(LOG, 'Aviso ao criar camada:', erro);
        }
    }

    function limparMarcador() {
        try {
            state.sdk.Map.removeAllFeaturesFromLayer({
                layerName: LAYER_NAME
            });
        } catch (erro) {
            // Camada ainda não existe.
        }
    }

    function irParaTarefaAtual() {
        const item = state.lote[state.indice];

        if (!item || !item.coords) {
            setStatus('Tarefa sem coordenadas.', 'erro');
            return;
        }

        const [latitudeTexto, longitudeTexto] = String(item.coords).split(',');
        const latitude = parseFloat(latitudeTexto);
        const longitude = parseFloat(longitudeTexto);

        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            setStatus('Coordenadas inválidas nesta tarefa.', 'erro');
            return;
        }

        registrarAtividade();

        try {
            state.sdk.Map.setMapCenter({
                lonLat: {
                    lon: longitude,
                    lat: latitude
                },
                zoomLevel: 19
            });

            limparMarcador();

            state.sdk.Map.addFeatureToLayer({
                layerName: LAYER_NAME,
                feature: {
                    id: `tarefa-${state.tipoLote}-${item.linhaDoc}`,
                    type: 'Feature',
                    geometry: {
                        type: 'Point',
                        coordinates: [longitude, latitude]
                    },
                    properties: {}
                }
            });
        } catch (erro) {
            console.error(LOG, 'Erro ao posicionar o mapa:', erro);
            setStatus('Erro ao centralizar o mapa.', 'erro');
        }
    }

    function converterEscolaParaTarefa(escola) {
        const latitude = parseFloat(escola.latitude);
        const longitude = parseFloat(escola.longitude);

        return {
            id: escola.id,
            linhaDoc: escola.id,
            tipo: 'ESCOLAS',
            escola: escola.escola,
            codigoInep: escola.codigo_inep,
            telefone: escola.telefone,
            categoriaAdministrativa: escola.categoria_administrativa ?? null,
            dependenciaAdministrativa: escola.dependencia_administrativa ?? null,
            situacaoFuncionamento: escola.situacao_funcionamento ?? null,
            situacaoFuncionamentoOrigem: escola.situacao_funcionamento_origem ?? null,
            restricaoAtendimento: escola.restricao_atendimento ?? null,
            municipio: escola.municipio,
            endereco: escola.endereco,
            coords: Number.isFinite(latitude) && Number.isFinite(longitude)
                ? `${latitude},${longitude}`
                : '',
            linkWaze: escola.link_waze,
            linkAreaEscolar: escola.link_area_escolar,
            status: escola.status,
            reservadoPara: escola.reservado_para
        };
    }

    function converterSaudeParaTarefa(estabelecimento) {
        const latitude = parseFloat(estabelecimento.latitude);
        const longitude = parseFloat(estabelecimento.longitude);

        return {
            id: estabelecimento.id,
            linhaDoc: estabelecimento.id,
            tipo: 'SAUDE',
            nome: estabelecimento.nome || estabelecimento.nome_fantasia,
            cnes: estabelecimento.cnes,
            categoria: estabelecimento.tipo ?? estabelecimento.tipo_estabelecimento ?? null,
            telefone: estabelecimento.telefone ?? estabelecimento.phone ?? null,
            ativo: normalizarAtivo(estabelecimento.ativo),
            situacao: normalizarAtivo(estabelecimento.ativo) === true ? 'Ativo' : (normalizarAtivo(estabelecimento.ativo) === false ? 'Inativo' : 'Não informado pela API'),
            statusRevisao: estabelecimento.status ?? null,
            municipio: estabelecimento.municipio,
            endereco: estabelecimento.logradouro,
            coords: Number.isFinite(latitude) && Number.isFinite(longitude)
                ? `${latitude},${longitude}`
                : '',
            linkWaze: estabelecimento.link_waze
        };
    }

    async function carregarItensApi(api, uf, usuario, filtros) {
        const cache = lerCache();
        const cacheValido = (
            cache &&
            cache.api === api &&
            cache.uf === uf &&
            cache.username === usuario &&
            mesmosFiltros(cache.filtros, filtros) &&
            Array.isArray(cache.itens) &&
            cache.itens.length > 0 &&
            cache.esquema === 5
        );

        if (cacheValido) {
            return {
                itens: cache.itens,
                doCache: true
            };
        }

        if (cache) {
            await liberarReservaApi({
                semInterface: true
            });
        }

        const corpo = {
            username: usuario,
            limite: TOOLBOX_API.LOTE
        };

        if (filtros) {
            corpo.filtros = filtros;
        }

        const resposta = await chamarApi(
            'POST',
            `/api/${api}/uf/${uf}/reservar`,
            corpo
        );

        // Servidor antigo ignora filtros em silêncio: libera o que reservou e avisa, em vez de trabalhar com itens fora do filtro.
        if (filtros && resposta.filtros_aplicados === undefined) {
            try {
                await chamarApi(
                    'POST',
                    `/api/${api}/uf/${uf}/liberar`,
                    { username: usuario },
                    { silencioso: true }
                );
            } catch (erro) {
                console.warn(LOG, 'Não foi possível liberar a reserva sem filtros:', erro.message);
            }

            throw new Error('O servidor ainda não aplica os filtros. Atualize a API do ToolboxWaze.');
        }

        const itens = (resposta.itens || resposta.unidades || resposta[api] || [])
            .slice(0, TOOLBOX_API.LOTE);

        if (itens.length > 0) {
            salvarCache({
                api,
                uf,
                username: usuario,
                filtros,
                itens
            });
        }

        return {
            itens,
            doCache: false
        };
    }

    async function carregarLote() {
        const usuario = ui('wmt-usuario').value.trim();
        const tipo = ui('wmt-tipo').value;
        const uf = ui('wmt-uf').value;

        if (!tipo || !uf) {
            resetarLote();
            abrirConfiguracao(true);
            setStatus('Selecione o tipo e a UF.');
            return;
        }

        if (!usuario) {
            resetarLote();
            abrirConfiguracao(true);
            setStatus('Digite seu nome de usuário.', 'erro');
            ui('wmt-usuario').focus();
            return;
        }

        store.set(KEYS.USUARIO, usuario);

        const sequencia = ++state.loadSeq;

        definirCarregando(true);
        setStatus('Carregando tarefas...');
        limparMarcador();
        renderizarInfoTarefa(null);
        renderizarVenues(null);
        ui('wmt-progresso').textContent = '';

        try {
            let tarefas = [];
            let mensagemLote = '';

            if (TIPOS[tipo].api) {
                const api = TIPOS[tipo].api;
                const filtros = filtrosAtuaisApi();
                const { itens, doCache } = await carregarItensApi(
                    api,
                    uf,
                    usuario,
                    filtros
                );

                tarefas = api === 'escolas'
                    ? itens.map(converterEscolaParaTarefa)
                    : itens.map(converterSaudeParaTarefa);

                mensagemLote = tarefas.length === 0
                    ? (
                        filtros
                            ? 'Nenhum estabelecimento disponível com estes filtros. Ajuste-os acima.'
                            : 'Nenhum item disponível para reserva nesta UF.'
                    )
                    : (
                        doCache
                            ? 'Lote recuperado do cache local.'
                            : ''
                    );
            } else {
                const resposta = await chamarAppsScript(
                    TIPOS[tipo].url,
                    {
                        action: 'getTarefas',
                        tipo,
                        uf,
                        usuario
                    }
                );

                tarefas = Array.isArray(resposta.tarefas)
                    ? resposta.tarefas
                    : [];

                if (sequencia === state.loadSeq) {
                    const total = Number(resposta.total) || 0;
                    const concluidos = Number(resposta.concluidos) || 0;
                    const percentual = total > 0
                        ? Math.round((concluidos / total) * 100)
                        : 0;

                    ui('wmt-progresso').textContent = (
                        `${percentual}% concluídos (${concluidos}/${total})`
                    );

                    if (tarefas.length === 0) {
                        mensagemLote = 'Todas concluídas!';
                    }
                }
            }

            if (sequencia !== state.loadSeq) {
                if (TIPOS[tipo].api) {
                    liberarReservaSeMudou({
                        semInterface: true
                    });
                }

                return;
            }

            state.lote = tarefas;
            state.indice = 0;
            state.tipoLote = tipo;
            state.ufLote = uf;

            if (tarefas.length === 0) {
                resetarLote(false);

                const saude = TIPOS[tipo].api === 'saude';

                setStatus(
                    mensagemLote || 'Nenhuma tarefa encontrada.',
                    saude ? 'aviso' : 'ok'
                );

                if (saude) {
                    abrirFiltros(true);
                }
            } else {
                mostrarTarefaAtual();
                abrirConfiguracao(false);
                abrirFiltros(false);

                if (mensagemLote) {
                    setStatus(mensagemLote);
                }
            }

            if (TIPOS[tipo].api === 'saude') {
                carregarFacetas();
            }
        } catch (erro) {
            if (sequencia !== state.loadSeq) {
                return;
            }

            resetarLote(false);
            setStatus(erro.message, 'erro');
        } finally {
            if (sequencia === state.loadSeq) {
                definirCarregando(false);
            }
        }
    }

    function resetarLote(limparStatus = true) {
        state.lote = [];
        state.indice = 0;
        state.venueSeq++;
        state.ultimoResultado = null;
        ui('wmt-contador').textContent = '0/0';
        desabilitarAcoes();
        ui('wmt-btn-centralizar').disabled = true;
        limparMarcador();
        renderizarInfoTarefa(null);
        renderizarVenues(null);

        if (limparStatus) {
            ui('wmt-progresso').textContent = '';
            limparEtapas();
        }
    }

    function mostrarTarefaAtual() {
        const item = state.lote[state.indice];

        if (!item) {
            resetarLote(false);
            return;
        }

        ui('wmt-contador').textContent = (
            `${state.indice + 1}/${state.lote.length}`
        );

        const anterior = state.ultimoResultado;
        state.ultimoResultado = null;

        setStatus(
            anterior
                ? `${anterior.texto} Próxima tarefa carregada.`
                : 'Aguardando ação...',
            anterior ? anterior.nivel : ''
        );

        ui('wmt-btn-resolvido').disabled = false;
        ui('wmt-btn-naolocalizado').disabled = false;
        ui('wmt-btn-pular').disabled = false;
        ui('wmt-btn-centralizar').disabled = false;

        detectarTema();
        renderizarInfoTarefa(item);
        irParaTarefaAtual();
        prepararVenues(item);
    }

    function irParaProxima() {
        if (state.salvando || state.carregando) {
            return;
        }

        if (state.indice < state.lote.length - 1) {
            state.indice++;
            mostrarTarefaAtual();
        } else {
            carregarLote();
        }
    }

    async function marcarAcao(acao) {
        if (state.salvando || state.carregando) {
            return;
        }

        const usuario = ui('wmt-usuario').value.trim();

        if (!usuario) {
            setStatus(
                'Digite seu nome de usuário antes de salvar.',
                'erro'
            );

            ui('wmt-usuario').focus();
            return;
        }

        const item = state.lote[state.indice];

        if (!item) {
            return;
        }

        state.salvando = true;
        let avancar = false;

        bloquearControlesEnvio(true);

        // Uma nova ação substitui qualquer falha parcial anterior desta tarefa.
        item.pendencia = null;

        renderizarVenues(item);
        registrarAtividade();
        setStatus('Salvando...');
        desabilitarAcoes();

        try {
            if (item.tipo === 'ESCOLAS' || item.tipo === 'SAUDE') {
                const conferencia = await salvarTarefaApi(item, acao, usuario);

                limparEtapas();
                statusAposConferencia(conferencia);
                avancar = true;
            } else {
                await chamarAppsScript(
                    TIPOS[state.tipoLote].url,
                    {
                        action: 'salvar',
                        tipo: state.tipoLote,
                        linha: item.linhaDoc,
                        status: acao,
                        usuario
                    }
                );

                state.ultimoResultado = { texto: 'Salvo!', nivel: 'ok' };
                avancar = true;
            }
        } catch (erro) {
            if (erro && erro.cancelado) {
                setStatus('Envio cancelado. Nada foi salvo.', 'aviso');
            } else if (item.pendencia) {
                setStatus(
                    item.pendencia.vinculoSalvo
                        ? 'Vínculo salvo. Confirmação de status pendente.'
                        : 'Status ainda não confirmado: ' + erro.message,
                    'erro'
                );
            } else {
                setStatus('Erro: ' + erro.message, 'erro');
            }

            habilitarAcoesDeEnvio();
        } finally {
            state.salvando = false;

            if (!avancar) {
                renderizarVenues(item);
            }

            bloquearControlesEnvio(false);
        }

        if (avancar) {
            irParaProxima();
        }
    }

    function registrarAtividade() {
        state.ultimaAtividade = Date.now();
    }

    function iniciarControleInatividade() {
        [
            'click',
            'keydown',
            'mousemove',
            'touchstart'
        ].forEach(evento => {
            document.addEventListener(
                evento,
                registrarAtividade,
                { passive: true }
            );
        });

        if (!state.inatividadeTimer) {
            state.inatividadeTimer = setInterval(
                verificarInatividade,
                INATIVIDADE_CHECK_MS
            );
        }
    }

    async function liberarReservaApi({
        semInterface = false
    } = {}) {
        const cache = lerCache();

        limparCache();

        if (
            !cache ||
            !cache.api ||
            !cache.uf ||
            !cache.username ||
            !obterToken()
        ) {
            return false;
        }

        try {
            await chamarApi(
                'POST',
                `/api/${cache.api}/uf/${cache.uf}/liberar`,
                {
                    username: cache.username
                },
                {
                    silencioso: true
                }
            );

            return true;
        } catch (erro) {
            console.warn(
                LOG,
                'Não foi possível liberar a reserva no servidor:',
                erro.message
            );

            return false;
        } finally {
            if (!semInterface) {
                definirCarregando(false);
            }
        }
    }

    async function liberarReservaSeMudou({
        semInterface = false
    } = {}) {
        const cache = lerCache();

        if (!cache) {
            return;
        }

        const mesmaSelecao = (
            TIPOS[ui('wmt-tipo').value]?.api === cache.api &&
            ui('wmt-uf').value === cache.uf &&
            ui('wmt-usuario').value.trim() === cache.username &&
            mesmosFiltros(cache.filtros, filtrosAtuaisApi())
        );

        if (mesmaSelecao) {
            return;
        }

        if (!semInterface) {
            definirCarregando(true);
            setStatus('Liberando reserva anterior...');
        }

        try {
            await liberarReservaApi({
                semInterface: true
            });
        } finally {
            if (!semInterface) {
                definirCarregando(false);
            }
        }
    }

    async function liberarReservaVencida() {
        const cache = lerCache();

        if (!cache) {
            return;
        }

        const ultima = Number(cache.ultimaAtividadeEm) ||
            Date.parse(cache.salvoEm) ||
            0;

        if (Date.now() - ultima < INATIVIDADE_LIMITE_MS) {
            return;
        }

        await liberarReservaApi({
            semInterface: true
        });

        setStatus('Reserva anterior expirou e foi liberada.');
    }

    async function verificarInatividade() {
        const cache = lerCache();

        if (!cache || !cache.uf || !cache.username) {
            return;
        }

        if (state.carregando || state.salvando) {
            return;
        }

        const registrada = Number(cache.ultimaAtividadeEm) || 0;
        const ultima = Math.max(state.ultimaAtividade, registrada);

        if (Date.now() - ultima < INATIVIDADE_LIMITE_MS) {
            if (state.ultimaAtividade > registrada) {
                cache.ultimaAtividadeEm = state.ultimaAtividade;
                store.setJson(KEYS.CACHE, cache);
            }

            return;
        }

        await liberarReservaApi({
            semInterface: true
        });

        if (TIPOS[state.tipoLote]?.api) {
            resetarLote(false);
            setStatus('Reserva liberada após 30 minutos sem uso.');
        }
    }

    async function verificarAtualizacao() {
        const versaoAtual = GM_info.script.version;
        const elemento = ui('wmt-versao');

        elemento.innerHTML = (
            '<span style="opacity:.7;">Verificando atualizações...</span>'
        );

        try {
            const resposta = await chamarAppsScript(
                URLS_API.RANKING,
                {
                    action: 'checkVersion'
                }
            );

            const maisRecente = resposta.versao;
            const comparacao = compararVersoes(
                versaoAtual,
                maisRecente
            );

            if (comparacao >= 0) {
                elemento.innerHTML = (
                    `<span class="wmt-badge" style="background:#2f9e5d;">✓ Atualizado (v${esc(versaoAtual)})</span>`
                );
            } else {
                elemento.innerHTML = (
                    `<a class="wmt-badge" style="background:#d64545;" href="${GREASYFORK_URL}" target="_blank" rel="noopener noreferrer">⚠️ Atualizar (v${esc(maisRecente)})</a>`
                );
            }
        } catch (erro) {
            elemento.innerHTML = (
                '<span class="wmt-erro-texto">Erro ao checar versão.</span>'
            );
        }
    }

    function renderizarTop5(titulo, lista) {
        const linhas = (Array.isArray(lista) ? lista : [])
            .slice(0, 5)
            .map((item, indice) => `
                <div class="wmt-top-linha">
                    <span>${MEDALHAS[indice]} ${esc(item.nome)}</span>
                    <b>${esc(item.quantidade)}</b>
                </div>
            `)
            .join('');

        return (
            `<div class="wmt-top">` +
            `<div class="wmt-top-titulo">${esc(titulo)}</div>` +
            (linhas || '<div class="wmt-vazio">Sem dados</div>') +
            `</div>`
        );
    }

    function renderizarRanking(dados) {
        const progresso = dados.progresso || {};

        RANK_TIPOS.forEach(tipo => {
            const percentual = Math.max(
                0,
                Math.min(
                    100,
                    Number(progresso[tipo.chave]) || 0
                )
            );

            const linha = state.painel.querySelector(
                `.wmt-prog-linha[data-tipo="${tipo.chave}"]`
            );

            if (!linha) {
                return;
            }

            linha.querySelector('.wmt-prog-pct').textContent = (
                `${percentual}%`
            );

            linha.querySelector('.wmt-bar > i').style.width = (
                `${percentual}%`
            );
        });

        const topPorTipo = dados.topPorTipo || {};

        ui('wmt-ranking-tops').innerHTML = RANK_TIPOS
            .filter(tipo => (
                RANK_TOP_FIXOS.includes(tipo.chave) ||
                (topPorTipo[tipo.chave] || []).length > 0
            ))
            .map(tipo => renderizarTop5(
                `${tipo.emoji} Top 5 · ${tipo.rotulo}`,
                topPorTipo[tipo.chave]
            ))
            .join('');

        ui('wmt-ranking-atualizado').textContent = (
            `Atualizado às ${new Date().toLocaleTimeString('pt-BR')}`
        );
    }

    async function buscarRanking() {
        if (state.rankingBuscando) {
            return;
        }

        state.rankingBuscando = true;

        try {
            renderizarRanking(
                await chamarAppsScript(
                    URLS_API.RANKING,
                    {
                        action: 'getRanking'
                    }
                )
            );
        } catch (erro) {
            ui('wmt-ranking-atualizado').textContent = (
                `Erro ao atualizar: ${erro.message}`
            );
        } finally {
            state.rankingBuscando = false;
        }
    }

    function pararAutoRefreshRanking() {
        if (state.rankingTimer) {
            clearInterval(state.rankingTimer);
            state.rankingTimer = null;
        }
    }

    function iniciarAutoRefreshRanking() {
        pararAutoRefreshRanking();

        state.rankingTimer = setInterval(() => {
            if (
                !document.hidden &&
                state.abaAtiva === 'ranking'
            ) {
                buscarRanking();
            }
        }, RANKING_REFRESH_MS);
    }

    function mostrarAba(nome) {
        state.abaAtiva = nome;
        detectarTema();
        const atividade = nome === 'atividade';

        ui('wmt-aba-atividade').style.display = (
            atividade ? 'block' : 'none'
        );

        ui('wmt-aba-ranking').style.display = (
            atividade ? 'none' : 'block'
        );

        ui('wmt-nav-atividade').classList.toggle(
            'ativa',
            atividade
        );

        ui('wmt-nav-ranking').classList.toggle(
            'ativa',
            !atividade
        );

        if (atividade) {
            pararAutoRefreshRanking();
        } else {
            buscarRanking();
            iniciarAutoRefreshRanking();
        }
    }

    function configurarEventos() {
        const ao = (id, evento, funcao) => {
            ui(id).addEventListener(evento, funcao);
        };

        ao('wmt-btn-token', 'click', abrirModalToken);

        ao('wmt-btn-remover-token', 'click', () => {
            if (!confirm('Remover o token salvo neste navegador?')) {
                return;
            }

            limparToken();
            atualizarStatusToken();
        });

        ao('wmt-tipo', 'change', async () => {
            store.set(KEYS.TIPO, ui('wmt-tipo').value);
            atualizarVisibilidadePorTipo();
            atualizarResumoConfig();
            state.loadSeq++;
            resetarLote();
            await liberarReservaSeMudou();
            carregarFacetas();
            carregarLote();
        });

        ao('wmt-uf', 'change', async () => {
            store.set(KEYS.UF, ui('wmt-uf').value);

            // Municípios mudam de UF para UF: o filtro de município volta para "Todos".
            state.facetas.municipios = [];
            ui('wmt-f-municipio').value = '';
            preencherMunicipios();
            store.setJson(KEYS.FILTROS, lerFiltrosUi());

            atualizarResumoConfig();
            state.loadSeq++;
            resetarLote();
            await liberarReservaSeMudou();
            carregarFacetas();
            carregarLote();
        });

        ao('wmt-usuario', 'change', async () => {
            store.set(
                KEYS.USUARIO,
                ui('wmt-usuario').value.trim()
            );

            atualizarResumoConfig();

            const havia = Boolean(lerCache());

            await liberarReservaSeMudou();

            if (havia && !lerCache()) {
                resetarLote();
            }

            if (
                ui('wmt-tipo').value &&
                ui('wmt-uf').value &&
                state.lote.length === 0
            ) {
                carregarLote();
            }
        });

        // Filtros da Saúde: cada mudança recarrega o lote já filtrado pela API.
        Object.keys(FILTRO_IDS).forEach(chave => {
            ao(FILTRO_IDS[chave], 'change', aoMudarFiltro);
        });

        ao('wmt-f-limpar', 'click', () => {
            aplicarFiltrosNaUi(FILTROS_PADRAO);
            aoMudarFiltro();
        });

        ao('wmt-venue', 'click', evento => {
            const button = evento.target.closest('[data-venue-action]');
            if (!button) return;
            atualizarVenuesAtual(button.dataset.venueAction === 'selecao');
        });
        ao('wmt-venue', 'change', evento => {
            if (!evento.target.matches('[data-venue-register-link]') || state.salvando) return;
            const item = state.lote[state.indice];
            if (item?.venues) item.venues.registrarLink = evento.target.checked;
        });

        ao('wmt-venue', 'change', evento => {
            const alvo = evento.target;

            if (!alvo || alvo.name !== 'wmt-venue-escolha' || state.salvando) {
                return;
            }

            const item = state.lote[state.indice];

            if (item && item.venues) {
                item.venues.escolha = alvo.value;
                renderizarVenues(item);
            }
        });

        ao('wmt-etapas', 'click', evento => {
            const botao = evento.target.closest('[data-etapas-acao]');

            if (!botao || state.salvando) {
                return;
            }

            if (botao.dataset.etapasAcao === 'retry-status') {
                tentarNovamenteStatus();
            } else if (botao.dataset.etapasAcao === 'fechar') {
                limparEtapas();
            }
        });

        ao('wmt-btn-centralizar', 'click', irParaTarefaAtual);

        // Lembra se o grupo "Sem alerta" da comparação estava aberto entre re-renderizações.
        ui('wmt-venue').addEventListener('toggle', evento => {
            const alvo = evento.target;

            if (!alvo || typeof alvo.matches !== 'function' || !alvo.matches('details[data-cmp-iguais]')) {
                return;
            }

            const item = state.lote[state.indice];

            if (item && item.venues) {
                item.venues.cmpIguaisAberto = alvo.open;
            }
        }, true);

        ao('wmt-btn-resolvido', 'click', () => marcarAcao('RESOLVIDO'));
        ao('wmt-btn-naolocalizado', 'click', () => marcarAcao('NÃO LOCALIZADO'));
        ao('wmt-btn-pular', 'click', () => marcarAcao('PULADO'));

        ao('wmt-btn-limpar', 'click', async () => {
            if (!confirm('Limpar o lote local reservado?')) {
                return;
            }

            const cache = lerCache();
            limparCache();
            resetarLote();
            setStatus('Reserva local limpa.');

            if (
                cache &&
                cache.api &&
                cache.uf &&
                cache.username &&
                obterToken()
            ) {
                try {
                    await chamarApi(
                        'POST',
                        `/api/${cache.api}/uf/${cache.uf}/liberar`,
                        {
                            username: cache.username
                        }
                    );

                    setStatus(
                        'Reserva local limpa e liberada no servidor.',
                        'ok'
                    );
                } catch (erro) {
                    console.warn(
                        LOG,
                        'Reserva não liberada no servidor:',
                        erro.message
                    );
                }
            }
        });

        ao('wmt-nav-atividade', 'click', () => mostrarAba('atividade'));
        ao('wmt-nav-ranking', 'click', () => mostrarAba('ranking'));
        ao('wmt-ranking-refresh', 'click', buscarRanking);
    }

    async function init() {
        if (state.iniciado) {
            return;
        }

        state.iniciado = true;

        try {
            migrarArmazenamentoLegado();
            injetarEstilos();
            criarCamadaMarcadores();
            inicializarLeituraVenuesSdk();
            await criarPainel();

            popularUFs();
            restaurarPreferencias();
            configurarEventos();
            atualizarVisibilidadePorTipo();
            atualizarStatusToken();
            carregarFacetas();
            abrirConfiguracao(!(ui('wmt-usuario').value.trim() && ui('wmt-uf').value));
            mostrarAba('atividade');
            setStatus('Selecione o tipo e a UF.');

            iniciarControleInatividade();
            verificarAtualizacao();

            await liberarReservaVencida();

            if (
                TIPOS[ui('wmt-tipo').value]?.api &&
                ui('wmt-uf').value &&
                lerCache()
            ) {
                carregarLote();
            }
        } catch (erro) {
            state.iniciado = false;
            console.error(
                LOG,
                'Falha ao montar a interface:',
                erro
            );
        }
    }

    function iniciarSdk() {
        const janela = unsafeWindow;

        if (
            !janela.SDK_INITIALIZED ||
            typeof janela.getWmeSdk !== 'function'
        ) {
            setTimeout(iniciarSdk, 300);
            return;
        }

        janela.SDK_INITIALIZED.then(() => {
            try {
                state.sdk = janela.getWmeSdk({
                    scriptId: SCRIPT_ID,
                    scriptName: SCRIPT_NAME
                });
            } catch (erro) {
                console.error(
                    LOG,
                    'Falha ao obter o WME SDK:',
                    erro
                );

                return;
            }

            if (state.sdk.State.isReady()) {
                init();
            } else {
                state.sdk.Events.once({
                    eventName: 'wme-ready'
                })
                    .then(init)
                    .catch(erro => console.error(
                        LOG,
                        'Erro aguardando wme-ready:',
                        erro
                    ));
            }
        }).catch(erro => console.error(
            LOG,
            'SDK_INITIALIZED rejeitado:',
            erro
        ));
    }

    iniciarSdk();
})();