// ==UserScript==
// @name         WME Tarefas Pendentes
// @version      2.0.0
// @description  Marcador de Tarefas (lombadas, semáforos, faixas, escolas...) com WME SDK, token pessoal validado e ranking
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

    /* ====================================================================
     * CONFIGURAÇÃO
     * ==================================================================== */

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

    const ESCOLAS_API = {
        BASE: 'https://wazetoolbox.acheireviews.com.br',
        TOKEN_PAGE: 'https://wazetoolbox.acheireviews.com.br/profile/api-token',
        LOTE: 50
    };

    const GREASYFORK_URL = 'https://greasyfork.org/pt-BR/scripts/587740-wme-marcador-de-tarefas';

    // Chaves do armazenamento do userscript (GM_*). Isolado da página, ao contrário do localStorage.
    const KEYS = {
        TOKEN: 'escolas_token',
        CACHE: 'escolas_reservadas',
        USUARIO: 'usuario',
        TIPO: 'ultimo_tipo',
        UF: 'ultima_uf'
    };
    const LEGACY_LS = {
        TOKEN: 'wme_marcador_escolas_token',
        CACHE: 'wme_marcador_escolas_reservadas'
    };

    const INATIVIDADE_LIMITE_MS = 30 * 60 * 1000;
    const INATIVIDADE_CHECK_MS = 60 * 1000;
    const RANKING_REFRESH_MS = 20000;

    const UFS_BRASIL = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
        'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
        'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'
    ];

    const TIPOS = {
        LOMBADAS: { rotulo: 'Lombadas', url: URLS_API.LOMBADAS },
        SEMAFOROS: { rotulo: 'Semáforos', url: URLS_API.SEMAFOROS },
        FAIXA_ELEVADA: { rotulo: 'Faixa Elevada', url: URLS_API.FAIXA_ELEVADA },
        ESTREITAMENTO: { rotulo: 'Estreitamento', url: URLS_API.ESTREITAMENTO, avisoPrecisao: true },
        CONFLUENCIA: { rotulo: 'Confluência', url: URLS_API.CONFLUENCIA, avisoPrecisao: true },
        ESCOLAS: { rotulo: 'Escolas', escolas: true }
    };

    const RANK_TIPOS = [
        { chave: 'lombadas', rotulo: 'Lombadas', emoji: '🚧', cor: '#4CAF50' },
        { chave: 'semaforos', rotulo: 'Semáforos', emoji: '🚦', cor: '#FF9800' },
        { chave: 'faixaElevada', rotulo: 'Faixa Elevada', emoji: '🚶', cor: '#2196F3' },
        { chave: 'estreitamento', rotulo: 'Estreitamento', emoji: '↔️', cor: '#9C27B0' },
        { chave: 'confluencia', rotulo: 'Confluência', emoji: '🔀', cor: '#009688' }
    ];
    // Top 5 sempre exibido para estes; os demais só aparecem se o servidor enviar dados.
    const RANK_TOP_FIXOS = ['lombadas', 'semaforos', 'faixaElevada'];
    const MEDALHAS = ['🥇', '🥈', '🥉', '4º', '5º'];

    const MARKER_ICON = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32">' +
        '<path d="M16 1C9.4 1 4 6.2 4 12.7 4 21.5 16 31 16 31s12-9.5 12-18.3C28 6.2 22.6 1 16 1z" fill="#e53935" stroke="#fff" stroke-width="2"/>' +
        '<circle cx="16" cy="12.5" r="4.5" fill="#fff"/></svg>'
    );

    /* ====================================================================
     * ESTADO
     * ==================================================================== */

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
        abaAtiva: 'atividade',
        rankingTimer: null,
        rankingBuscando: false,
        ultimaAtividade: Date.now(),
        inatividadeTimer: null,
        iniciado: false
    };

    /* ====================================================================
     * UTILITÁRIOS
     * ==================================================================== */

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
        const tam = Math.max(pa.length, pb.length);

        for (let i = 0; i < tam; i++) {
            const diff = (pa[i] || 0) - (pb[i] || 0);
            if (diff !== 0) return diff > 0 ? 1 : -1;
        }
        return 0;
    }

    function ui(id) {
        return state.painel ? state.painel.querySelector('#' + id) : null;
    }

    /* ====================================================================
     * ARMAZENAMENTO (GM_*)
     * ==================================================================== */

    const store = {
        get(chave, padrao) {
            try {
                const v = GM_getValue(chave);
                return v === undefined || v === null ? padrao : v;
            } catch (e) {
                return padrao;
            }
        },
        set(chave, valor) {
            try { GM_setValue(chave, valor); } catch (e) { console.warn(LOG, 'Falha ao gravar', chave, e); }
        },
        del(chave) {
            try { GM_deleteValue(chave); } catch (e) { /* ignora */ }
        },
        getJson(chave) {
            try {
                const bruto = store.get(chave, null);
                return bruto ? JSON.parse(bruto) : null;
            } catch (e) {
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
        } catch (e) { /* localStorage indisponível */ }
    }

    const obterToken = () => String(store.get(KEYS.TOKEN, '') || '');
    const salvarToken = token => store.set(KEYS.TOKEN, token.trim());
    const limparToken = () => store.del(KEYS.TOKEN);

    const lerCacheEscolas = () => store.getJson(KEYS.CACHE);
    const limparCacheEscolas = () => store.del(KEYS.CACHE);
    function salvarCacheEscolas(dados) {
        store.setJson(KEYS.CACHE, {
            uf: dados.uf,
            username: dados.username,
            escolas: dados.escolas,
            salvoEm: new Date().toISOString(),
            ultimaAtividadeEm: Date.now()
        });
    }

    /* ====================================================================
     * REDE
     * ==================================================================== */

    function descreverErroRede(titulo, url, res) {
        let host = url;
        try { host = new URL(url).host; } catch (e) { /* mantém a url crua */ }

        const partes = [];

        if (res) {
            if (res.error) partes.push(String(res.error));
            if (res.status) partes.push(`HTTP ${res.status}${res.statusText ? ' ' + res.statusText : ''}`);

            const corpo = String(res.responseText || '').replace(/\s+/g, ' ').trim();
            if (corpo) partes.push(corpo.slice(0, 150));
        }

        if (!navigator.onLine) partes.push('navegador sem internet');

        const detalhe = partes.length
            ? partes.join(' | ')
            : 'sem detalhes (possível bloqueio de @connect no Tampermonkey, CORS, DNS ou servidor fora do ar)';

        console.error(LOG, titulo, '→', url, res);
        return new Error(`${titulo} [${host}]: ${detalhe}`);
    }

    function gmRequest({ method = 'GET', url, headers = {}, body, timeout = 30000 }) {
        return new Promise((resolve, reject) => {
            try {
                GM_xmlhttpRequest({
                    method,
                    url,
                    headers,
                    data: body,
                    timeout,
                    onload: resolve,
                    onerror: res => reject(descreverErroRede('Falha de conexão', url, res)),
                    ontimeout: res => reject(descreverErroRede(`Tempo limite (${Math.round(timeout / 1000)}s)`, url, res)),
                    onabort: res => reject(descreverErroRede('Requisição cancelada', url, res))
                });
            } catch (e) {
                reject(descreverErroRede('Requisição não iniciada', url, { error: e && e.message ? e.message : String(e) }));
            }
        });
    }

    function lerJson(res, mensagemErro) {
        try {
            return JSON.parse(res.responseText);
        } catch (e) {
            throw new Error(mensagemErro);
        }
    }

    async function chamarAppsScript(url, payload) {
        const res = await gmRequest({
            method: 'POST',
            url,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const dados = lerJson(res, 'Erro ao ler a resposta do servidor.');

        if (dados.resultado !== 'sucesso') {
            throw new Error(dados.mensagem || 'Erro desconhecido do servidor.');
        }
        return dados;
    }

    async function validarToken(token) {
        const res = await gmRequest({
            url: ESCOLAS_API.BASE + '/api/auth/validate',
            headers: { 'X-API-TOKEN': token, 'Accept': 'application/json' }
        });
        const corpo = String(res.responseText || '');
        console.log(LOG, 'validar token → HTTP', res.status, '|', corpo.slice(0, 300));

        let dados = null;
        try { dados = JSON.parse(corpo); } catch (e) { /* tratado abaixo */ }

        if (res.status === 200 && dados && dados.valid === true) return dados;

        const detalhe = dados
            ? (dados.error || `valid=${dados.valid}`)
            : (corpo.slice(0, 120) || 'resposta vazia');
        throw new Error(`Token inválido (HTTP ${res.status}): ${detalhe}`);
    }

    async function chamarApiEscolas(metodo, caminho, payload, opcoes = {}) {
        const token = obterToken();

        if (!token) {
            if (!opcoes.silencioso) abrirModalToken();
            throw new Error('Informe o Token da API antes de usar Escolas.');
        }

        const res = await gmRequest({
            method: metodo,
            url: ESCOLAS_API.BASE + caminho,
            headers: {
                'X-API-TOKEN': token,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: payload ? JSON.stringify(payload) : undefined
        });

        let dados = null;
        try { dados = JSON.parse(res.responseText); } catch (e) { /* tratado abaixo */ }

        console.log(LOG, metodo, caminho, '→ HTTP', res.status, '|', String(res.responseText || '').slice(0, 300));

        if (res.status === 401 || res.status === 403) {
            if (!opcoes.silencioso) abrirModalToken();
            throw new Error((dados && dados.error) || 'Token recusado. Gere um novo token.');
        }
        if (res.status >= 400) {
            throw new Error((dados && dados.error) || `Erro HTTP ${res.status}`);
        }
        if (dados === null) throw new Error('Resposta inválida da API de Escolas.');

        return dados;
    }

    /* ====================================================================
     * ESTILOS
     * ==================================================================== */

    function injetarEstilos() {
        if (document.getElementById('wmt-style')) return;

        const css = `
            .wmt { padding: 10px; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 12px; }
            .wmt, .wmt * { box-sizing: border-box; }
            .wmt h4 { margin: 0 0 10px; font-weight: 700; }
            .wmt label { display: block; margin: 0 0 4px; font-weight: 600; }
            .wmt input, .wmt select { width: 100%; padding: 6px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px; }
            .wmt button { border: none; border-radius: 4px; color: #fff; font-weight: 700; padding: 9px 6px; cursor: pointer; }
            .wmt button:disabled { opacity: .45; cursor: not-allowed; }
            .wmt-row { display: flex; gap: 8px; margin-bottom: 10px; }
            .wmt-row > button { flex: 1; }
            .wmt-azul { background: #2196F3; } .wmt-verde { background: #4CAF50; }
            .wmt-vermelho { background: #f44336; } .wmt-cinza { background: #607D8B; }
            .wmt-laranja { background: #FB8C00; }
            .wmt-full { width: 100%; }
            .wmt-versao { margin-bottom: 12px; text-align: center; font-size: 11px; }
            .wmt-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; color: #fff; font-weight: 700; text-decoration: none; }
            .wmt-tabs { display: flex; margin-bottom: 12px; border-bottom: 2px solid rgba(128,128,128,.3); }
            .wmt .wmt-tab { flex: 1; background: none; border: none; border-bottom: 3px solid transparent; border-radius: 0; color: #888; padding: 8px 0; font-size: 12px; }
            .wmt .wmt-tab.ativa { border-bottom-color: #2196F3; color: #2196F3; }
            .wmt-aviso { margin-bottom: 10px; padding: 6px 8px; border: 1px solid #FFE0B2; border-radius: 4px; background: #FFF3E0; color: #E65100; font-size: 11px; }
            .wmt-barra { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 10px; padding: 8px; border-radius: 4px; background: rgba(128,128,128,.14); }
            .wmt-barra #wmt-contador { font-weight: 700; white-space: nowrap; }
            .wmt-barra #wmt-status { font-size: 11px; font-weight: 700; text-align: right; opacity: .85; }
            #wmt-status[data-nivel="erro"] { color: #f44336; opacity: 1; }
            #wmt-status[data-nivel="ok"] { color: #4CAF50; opacity: 1; }
            .wmt-info { margin-bottom: 10px; padding: 8px 10px; border: 1px solid rgba(128,128,128,.35); border-radius: 4px; font-size: 11px; line-height: 1.5; }
            .wmt-info b { font-size: 12px; }
            .wmt-info a { margin-right: 10px; font-weight: 700; }
            .wmt-token-status { margin-bottom: 6px; font-size: 11px; opacity: .8; }
            .wmt-progresso { margin-top: 10px; text-align: center; font-weight: 700; }
            .wmt-prog-linha { margin-bottom: 8px; }
            .wmt-prog-topo { display: flex; justify-content: space-between; margin-bottom: 3px; font-size: 11px; }
            .wmt-bar { height: 8px; overflow: hidden; border-radius: 4px; background: rgba(128,128,128,.25); }
            .wmt-bar > i { display: block; width: 0; height: 100%; transition: width .4s; }
            .wmt-top { margin-bottom: 8px; padding: 8px 10px; border: 1px solid rgba(128,128,128,.35); border-radius: 4px; }
            .wmt-top-titulo { margin-bottom: 4px; font-size: 12px; font-weight: 700; }
            .wmt-top-linha { display: flex; justify-content: space-between; padding: 2px 0; font-size: 11px; }
            .wmt-vazio { font-size: 11px; opacity: .6; }
            .wmt-rodape { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; }
            .wmt-rodape span { font-size: 10px; opacity: .7; }
            .wmt-flutuante { position: fixed; right: 12px; bottom: 12px; z-index: 9999; width: 280px; border: 1px solid #ccc; border-radius: 8px; background: #fff; color: #222; box-shadow: 0 4px 16px rgba(0,0,0,.25); }

            .wmt-overlay { position: fixed; inset: 0; z-index: 999999; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,.55); font-family: Arial, sans-serif; }
            .wmt-modal { width: 420px; max-width: 92%; padding: 20px; border-radius: 10px; background: #fff; color: #222; }
            .wmt-modal h3 { margin: 0 0 12px; }
            .wmt-modal ol { margin: 0 0 16px; padding-left: 20px; font-size: 13px; line-height: 1.6; }
            .wmt-modal a.wmt-btn-link, .wmt-modal a.wmt-btn-link:link, .wmt-modal a.wmt-btn-link:visited,
            .wmt-modal a.wmt-btn-link:hover, .wmt-modal a.wmt-btn-link:active { display: block !important; margin-bottom: 14px; padding: 10px; border-radius: 6px; background: #2196F3 !important; color: #fff !important; font-size: 14px !important; font-weight: 700; line-height: 1.3 !important; text-align: center; text-decoration: none !important; }
            .wmt-modal label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 700; }
            .wmt-modal input { width: 100%; padding: 9px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; }
            .wmt-modal .wmt-modal-status { min-height: 16px; margin-top: 8px; font-size: 12px; color: #666; }
            .wmt-modal .wmt-modal-botoes { display: flex; gap: 8px; margin-top: 14px; }
            .wmt-modal .wmt-modal-botoes button { flex: 1; padding: 10px; border: none; border-radius: 6px; color: #fff !important; font-size: 14px !important; font-weight: 700; line-height: 1.3 !important; cursor: pointer; }
            .wmt-modal .wmt-modal-botoes button:disabled { opacity: .5; cursor: wait; }
        `;

        const style = document.createElement('style');
        style.id = 'wmt-style';
        style.textContent = css;
        document.head.appendChild(style);
    }

    /* ====================================================================
     * MODAL DO TOKEN
     * ==================================================================== */

    function abrirModalToken() {
        if (document.getElementById('wmt-token-modal')) return;

        const overlay = document.createElement('div');
        overlay.id = 'wmt-token-modal';
        overlay.className = 'wmt-overlay';
        overlay.innerHTML = `
            <div class="wmt-modal" role="dialog" aria-modal="true" aria-labelledby="wmt-modal-titulo">
                <h3 id="wmt-modal-titulo">🔑 Gerar e salvar seu token</h3>
                <ol>
                    <li>Clique em <b>Abrir página do token</b>.</li>
                    <li>Faça login no ToolboxWaze, se necessário.</li>
                    <li>Clique em <b>Gerar novo token</b>.</li>
                    <li>Copie o token gerado.</li>
                    <li>Cole no campo abaixo e clique em <b>Validar e salvar</b>.</li>
                </ol>
                <a class="wmt-btn-link" href="${ESCOLAS_API.TOKEN_PAGE}" target="_blank" rel="noopener noreferrer" style="color:#fff !important; text-decoration:none !important;">Abrir página do token</a>
                <label for="wmt-token-input">Cole seu token:</label>
                <input type="password" id="wmt-token-input" placeholder="Cole aqui o token" autocomplete="off" spellcheck="false">
                <div class="wmt-modal-status" id="wmt-token-modal-status"></div>
                <div class="wmt-modal-botoes">
                    <button type="button" id="wmt-token-salvar" style="background:#4CAF50;">Validar e salvar</button>
                    <button type="button" id="wmt-token-fechar" style="background:#607D8B;">Fechar</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        const input = overlay.querySelector('#wmt-token-input');
        const status = overlay.querySelector('#wmt-token-modal-status');
        const btnSalvar = overlay.querySelector('#wmt-token-salvar');
        const btnFechar = overlay.querySelector('#wmt-token-fechar');

        const onKey = ev => {
            if (ev.key === 'Escape') {
                ev.stopPropagation();
                fechar();
            }
        };
        function fechar() {
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
        }
        const mostrar = (texto, cor) => {
            status.textContent = texto;
            status.style.color = cor;
        };

        async function salvar() {
            const token = input.value.trim();

            if (!token) {
                mostrar('Cole o token gerado no site.', '#f44336');
                return;
            }

            btnSalvar.disabled = true;
            mostrar('Validando token...', '#666');

            try {
                const dados = await validarToken(token);
                salvarToken(token);
                mostrar(`Token válido para ${dados.username || 'seu usuário'}.`, '#4CAF50');
                atualizarStatusToken();

                const campoUsuario = ui('wmt-usuario');
                if (campoUsuario && !campoUsuario.value.trim() && dados.username) {
                    campoUsuario.value = dados.username;
                    store.set(KEYS.USUARIO, dados.username);
                }
                setTimeout(fechar, 1200);
            } catch (erro) {
                mostrar(erro.message, '#f44336');
                btnSalvar.disabled = false;
            }
        }

        btnSalvar.addEventListener('click', salvar);
        btnFechar.addEventListener('click', fechar);
        input.addEventListener('keydown', ev => { if (ev.key === 'Enter') salvar(); });
        overlay.addEventListener('mousedown', ev => { if (ev.target === overlay) fechar(); });
        document.addEventListener('keydown', onKey, true);
        input.focus();
    }

    function atualizarStatusToken() {
        const el = ui('wmt-token-status');
        if (el) el.textContent = obterToken() ? 'Token salvo neste navegador.' : 'Nenhum token salvo.';
    }

    /* ====================================================================
     * PAINEL
     * ==================================================================== */

    function htmlPainel() {
        const opcoesTipo = Object.keys(TIPOS)
            .map(chave => `<option value="${chave}">${esc(TIPOS[chave].rotulo)}</option>`)
            .join('');

        const linhasProgresso = RANK_TIPOS.map(t => `
            <div class="wmt-prog-linha" data-tipo="${t.chave}">
                <div class="wmt-prog-topo"><span>${t.emoji} ${esc(t.rotulo)}</span><b class="wmt-prog-pct">0%</b></div>
                <div class="wmt-bar"><i style="background:${t.cor};"></i></div>
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
                <div id="wmt-bloco-token" style="display:none;">
                    <label>Token da API (necessário para Escolas):</label>
                    <div id="wmt-token-status" class="wmt-token-status">Verificando...</div>
                    <div class="wmt-row">
                        <button type="button" id="wmt-btn-token" class="wmt-azul">🔑 Token da API</button>
                        <button type="button" id="wmt-btn-remover-token" class="wmt-vermelho">Remover</button>
                    </div>
                </div>

                <label for="wmt-usuario">Nome do Usuário <span style="color:#f44336;">*</span>:</label>
                <input type="text" id="wmt-usuario" placeholder="Seu nome de editor (obrigatório)" autocomplete="off">

                <label for="wmt-tipo">Tipo de Tarefa:</label>
                <select id="wmt-tipo">${opcoesTipo}</select>

                <label for="wmt-uf">Estado (UF):</label>
                <select id="wmt-uf"></select>

                <div id="wmt-aviso-precisao" class="wmt-aviso" style="display:none;">
                    📍 Localização da sinalização. Uma análise mais precisa é necessária.
                </div>

                <div class="wmt-barra">
                    <span id="wmt-contador">0/0</span>
                    <span id="wmt-status">Inicializando...</span>
                </div>

                <div id="wmt-info" class="wmt-info" style="display:none;"></div>

                <div class="wmt-row">
                    <button type="button" id="wmt-btn-resolvido" class="wmt-verde" disabled>✓ RESOLVIDO</button>
                    <button type="button" id="wmt-btn-naolocalizado" class="wmt-vermelho" disabled>❌ Ñ ACHADO</button>
                </div>

                <button type="button" id="wmt-btn-pular" class="wmt-laranja wmt-full" style="display:none; margin-bottom:10px;" disabled>⏭ PULAR</button>
                <button type="button" id="wmt-btn-proximo" class="wmt-azul wmt-full" style="padding:12px;" disabled>PRÓXIMO ➡</button>
                <button type="button" id="wmt-btn-limpar" class="wmt-cinza wmt-full" style="display:none; margin-top:8px; font-size:11px;">🧹 Limpar reserva local</button>

                <div id="wmt-progresso" class="wmt-progresso"></div>
            </div>

            <div id="wmt-aba-ranking" style="display:none;">
                <div style="font-weight:700; margin-bottom:8px;">📊 Progresso Geral por Tipo</div>
                ${linhasProgresso}
                <div id="wmt-ranking-tops" style="margin-top:12px;"></div>
                <div class="wmt-rodape">
                    <span id="wmt-ranking-atualizado"></span>
                    <button type="button" id="wmt-ranking-refresh" class="wmt-cinza">🔄 Atualizar</button>
                </div>
            </div>
        `;
    }

    async function criarPainel() {
        const container = document.createElement('div');
        container.id = 'wmt-container';
        container.className = 'wmt';
        container.innerHTML = htmlPainel();

        try {
            const { tabLabel, tabPane } = await state.sdk.Sidebar.registerScriptTab();
            tabLabel.innerHTML = '<span title="Marcador de Tarefas" style="font-size:15px;">📍</span>';
            tabPane.appendChild(container);
        } catch (err) {
            console.warn(LOG, 'Não foi possível registrar a aba pelo SDK; usando painel alternativo.', err);
            const hospedeiro = document.querySelector('#user-info');

            if (hospedeiro) {
                hospedeiro.appendChild(container);
            } else {
                container.classList.add('wmt-flutuante');
                document.body.appendChild(container);
            }
        }

        state.painel = container;
    }

    function popularUFs() {
        const select = ui('wmt-uf');
        select.innerHTML = '<option value="">Selecione uma UF</option>' +
            UFS_BRASIL.map(uf => `<option value="${uf}">${uf}</option>`).join('');
    }

    function restaurarPreferencias() {
        const usuarioSalvo = String(store.get(KEYS.USUARIO, '') || '');
        let usuario = usuarioSalvo;

        if (!usuario) {
            try {
                const info = state.sdk.State.getUserInfo();
                if (info && info.userName) usuario = info.userName;
            } catch (e) { /* SDK sem getUserInfo ou usuário não logado */ }
        }
        ui('wmt-usuario').value = usuario;

        const tipo = String(store.get(KEYS.TIPO, '') || '');
        if (TIPOS[tipo]) ui('wmt-tipo').value = tipo;

        const uf = String(store.get(KEYS.UF, '') || '');
        if (UFS_BRASIL.includes(uf)) ui('wmt-uf').value = uf;
    }

    function atualizarVisibilidadePorTipo() {
        const cfg = TIPOS[ui('wmt-tipo').value] || {};

        ui('wmt-aviso-precisao').style.display = cfg.avisoPrecisao ? 'block' : 'none';
        ui('wmt-bloco-token').style.display = cfg.escolas ? 'block' : 'none';
        ui('wmt-btn-pular').style.display = cfg.escolas ? 'block' : 'none';
        ui('wmt-btn-limpar').style.display = cfg.escolas ? 'block' : 'none';
    }

    function setStatus(mensagem, nivel) {
        const el = ui('wmt-status');
        if (!el) return;
        el.textContent = mensagem;
        el.dataset.nivel = nivel || '';
    }

    function desabilitarAcoes() {
        ['wmt-btn-resolvido', 'wmt-btn-naolocalizado', 'wmt-btn-pular', 'wmt-btn-proximo']
            .forEach(id => { ui(id).disabled = true; });
    }

    function definirCarregando(valor) {
        state.carregando = valor;
        ['wmt-uf', 'wmt-tipo', 'wmt-usuario'].forEach(id => { ui(id).disabled = valor; });
        if (valor) desabilitarAcoes();
    }

    function renderizarInfoTarefa(item) {
        const el = ui('wmt-info');

        if (!item) {
            el.style.display = 'none';
            el.innerHTML = '';
            return;
        }

        let html;

        if (item.tipo === 'ESCOLAS') {
            const linkWaze = urlSegura(item.linkWaze);
            const linkArea = urlSegura(item.linkAreaEscolar);
            const linkDetalhes = item.id !== undefined && item.id !== null && item.id !== ''
                ? `${ESCOLAS_API.BASE}/escolas/${encodeURIComponent(item.id)}`
                : '';

            html = `<b>${esc(item.escola || 'Escola')}</b><br>` +
                (item.municipio ? `${esc(item.municipio)}<br>` : '') +
                (item.endereco ? `${esc(item.endereco)}<br>` : '') +
                (item.codigoInep ? `INEP: ${esc(item.codigoInep)}<br>` : '') +
                (linkDetalhes ? `<a href="${esc(linkDetalhes)}" target="_blank" rel="noopener noreferrer">🔎 Mais dados</a>` : '') +
                (linkWaze ? `<a href="${esc(linkWaze)}" target="_blank" rel="noopener noreferrer">Abrir no Waze</a>` : '') +
                (linkArea ? `<a href="${esc(linkArea)}" target="_blank" rel="noopener noreferrer">Área escolar</a>` : '');
        } else {
            const rotulo = (TIPOS[state.tipoLote] || {}).rotulo || '';
            html = `<b>${esc(rotulo)}</b> · ${esc(item.coords || 'sem coordenadas')}`;
        }

        el.innerHTML = html;
        el.style.display = 'block';
    }

    /* ====================================================================
     * MAPA (WME SDK)
     * ==================================================================== */

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
        } catch (e) {
            console.warn(LOG, 'Aviso ao criar camada:', e);
        }
    }

    function limparMarcador() {
        try {
            state.sdk.Map.removeAllFeaturesFromLayer({ layerName: LAYER_NAME });
        } catch (e) { /* camada ainda não existe */ }
    }

    function irParaTarefaAtual() {
        const item = state.lote[state.indice];

        if (!item || !item.coords) {
            setStatus('Tarefa sem coordenadas.', 'erro');
            return;
        }

        const [latStr, lonStr] = String(item.coords).split(',');
        const lat = parseFloat(latStr);
        const lon = parseFloat(lonStr);

        if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
            setStatus('Coordenadas inválidas nesta tarefa.', 'erro');
            return;
        }

        registrarAtividade();

        try {
            state.sdk.Map.setMapCenter({ lonLat: { lon, lat }, zoomLevel: 19 });
            limparMarcador();
            state.sdk.Map.addFeatureToLayer({
                layerName: LAYER_NAME,
                feature: {
                    id: `tarefa-${state.tipoLote}-${item.linhaDoc}`,
                    type: 'Feature',
                    geometry: { type: 'Point', coordinates: [lon, lat] },
                    properties: {}
                }
            });
        } catch (e) {
            console.error(LOG, 'Erro ao posicionar o mapa:', e);
            setStatus('Erro ao centralizar o mapa.', 'erro');
        }
    }

    /* ====================================================================
     * TAREFAS
     * ==================================================================== */

    function converterEscolaParaTarefa(escola) {
        const lat = parseFloat(escola.latitude);
        const lon = parseFloat(escola.longitude);

        return {
            id: escola.id,
            linhaDoc: escola.id,
            tipo: 'ESCOLAS',
            escola: escola.escola,
            codigoInep: escola.codigo_inep,
            municipio: escola.municipio,
            endereco: escola.endereco,
            coords: Number.isFinite(lat) && Number.isFinite(lon) ? `${lat},${lon}` : '',
            linkWaze: escola.link_waze,
            linkAreaEscolar: escola.link_area_escolar,
            status: escola.status,
            reservadoPara: escola.reservado_para
        };
    }

    async function carregarEscolas(uf, usuario) {
        const cache = lerCacheEscolas();
        const cacheValido = cache && cache.uf === uf && cache.username === usuario &&
            Array.isArray(cache.escolas) && cache.escolas.length > 0;

        if (cacheValido) {
            return { escolas: cache.escolas, doCache: true };
        }

        // Havia um lote de outra UF/usuário (ou esgotado): libera antes de reservar um novo.
        if (cache) await liberarReservaEscolas();

        const resposta = await chamarApiEscolas('POST', `/api/escolas/uf/${uf}/reservar`, {
            username: usuario,
            limite: ESCOLAS_API.LOTE
        });
        const escolas = (resposta.escolas || []).slice(0, ESCOLAS_API.LOTE);

        if (escolas.length > 0) salvarCacheEscolas({ uf, username: usuario, escolas });

        return { escolas, doCache: false };
    }

    async function carregarLote() {
        const usuario = ui('wmt-usuario').value.trim();
        const tipo = ui('wmt-tipo').value;
        const uf = ui('wmt-uf').value;

        if (!tipo || !uf) {
            resetarLote();
            setStatus('Selecione o tipo e a UF.');
            return;
        }

        if (!usuario) {
            resetarLote();
            setStatus('Digite seu nome de usuário.', 'erro');
            ui('wmt-usuario').focus();
            return;
        }

        store.set(KEYS.USUARIO, usuario);

        const seq = ++state.loadSeq;
        definirCarregando(true);
        setStatus('Carregando tarefas...');
        limparMarcador();
        renderizarInfoTarefa(null);
        ui('wmt-progresso').textContent = '';

        try {
            let tarefas;
            let mensagemLote = '';

            if (TIPOS[tipo].escolas) {
                const { escolas, doCache } = await carregarEscolas(uf, usuario);
                tarefas = escolas.map(converterEscolaParaTarefa);
                mensagemLote = tarefas.length === 0
                    ? 'Nenhuma escola disponível para reserva nesta UF.'
                    : (doCache ? 'Lote recuperado do cache local.' : '');
            } else {
                const resposta = await chamarAppsScript(TIPOS[tipo].url, {
                    action: 'getTarefas', tipo, uf, usuario
                });
                tarefas = Array.isArray(resposta.tarefas) ? resposta.tarefas : [];

                if (seq === state.loadSeq) {
                    const total = Number(resposta.total) || 0;
                    const concluidos = Number(resposta.concluidos) || 0;
                    const perc = total > 0 ? Math.round((concluidos / total) * 100) : 0;
                    ui('wmt-progresso').textContent = `${perc}% concluídos (${concluidos}/${total})`;
                    if (tarefas.length === 0) mensagemLote = 'Todas concluídas!';
                }
            }

            if (seq !== state.loadSeq) { // uma carga mais nova assumiu
                if (TIPOS[tipo].escolas) liberarReservaSeMudou({ semUi: true });
                return;
            }

            state.lote = tarefas;
            state.indice = 0;
            state.tipoLote = tipo;
            state.ufLote = uf;

            if (tarefas.length === 0) {
                resetarLote(false);
                setStatus(mensagemLote || 'Nenhuma tarefa encontrada.', 'ok');
            } else {
                mostrarTarefaAtual();
                if (mensagemLote) setStatus(mensagemLote);
            }
        } catch (erro) {
            if (seq !== state.loadSeq) return;
            resetarLote(false);
            setStatus(erro.message, 'erro');
        } finally {
            if (seq === state.loadSeq) definirCarregando(false);
        }
    }

    function resetarLote(limparStatus = true) {
        state.lote = [];
        state.indice = 0;
        ui('wmt-contador').textContent = '0/0';
        desabilitarAcoes();
        limparMarcador();
        renderizarInfoTarefa(null);
        if (limparStatus) ui('wmt-progresso').textContent = '';
    }

    function mostrarTarefaAtual() {
        const item = state.lote[state.indice];

        if (!item) {
            resetarLote(false);
            return;
        }

        ui('wmt-contador').textContent = `${state.indice + 1}/${state.lote.length}`;
        setStatus('Aguardando ação...');
        ui('wmt-btn-resolvido').disabled = false;
        ui('wmt-btn-naolocalizado').disabled = false;
        ui('wmt-btn-pular').disabled = false;
        ui('wmt-btn-proximo').disabled = true;

        renderizarInfoTarefa(item);
        irParaTarefaAtual();
    }

    function irParaProxima() {
        if (state.indice < state.lote.length - 1) {
            state.indice++;
            mostrarTarefaAtual();
        } else {
            carregarLote();
        }
    }

    async function marcarAcao(acao) {
        if (state.salvando || state.carregando) return;

        const usuario = ui('wmt-usuario').value.trim();

        if (!usuario) {
            setStatus('Digite seu nome de usuário antes de salvar.', 'erro');
            ui('wmt-usuario').focus();
            return;
        }

        const item = state.lote[state.indice];
        if (!item) return;

        state.salvando = true;
        registrarAtividade();
        setStatus('Salvando...');
        ui('wmt-btn-resolvido').disabled = true;
        ui('wmt-btn-naolocalizado').disabled = true;
        ui('wmt-btn-pular').disabled = true;

        try {
            if (item.tipo === 'ESCOLAS') {
                const statusApi = {
                    'RESOLVIDO': 'confirmado',
                    'NÃO LOCALIZADO': 'nao_localizado',
                    'PULADO': 'pulado'
                }[acao];

                if (!statusApi) throw new Error('Ação inválida para Escolas.');

                await chamarApiEscolas('PATCH', `/api/escolas/${item.id}/status`, {
                    status: statusApi,
                    username: usuario
                });

                const cache = lerCacheEscolas();
                if (cache && Array.isArray(cache.escolas)) {
                    cache.escolas = cache.escolas.filter(escola => escola.id !== item.id);

                    if (cache.escolas.length === 0) limparCacheEscolas();
                    else salvarCacheEscolas(cache);
                }
            } else {
                await chamarAppsScript(TIPOS[state.tipoLote].url, {
                    action: 'salvar',
                    tipo: state.tipoLote,
                    linha: item.linhaDoc,
                    status: acao,
                    usuario
                });
            }

            setStatus('Salvo!', 'ok');
            ui('wmt-btn-proximo').disabled = false;
            ui('wmt-btn-proximo').focus();
        } catch (erro) {
            setStatus('Erro: ' + erro.message, 'erro');
            ui('wmt-btn-resolvido').disabled = false;
            ui('wmt-btn-naolocalizado').disabled = false;
            ui('wmt-btn-pular').disabled = false;
        } finally {
            state.salvando = false;
        }
    }

    /* ====================================================================
     * INATIVIDADE (libera a reserva de escolas)
     * ==================================================================== */

    function registrarAtividade() {
        state.ultimaAtividade = Date.now();
    }

    function iniciarControleInatividade() {
        ['click', 'keydown', 'mousemove', 'touchstart'].forEach(evento => {
            document.addEventListener(evento, registrarAtividade, { passive: true });
        });

        if (!state.inatividadeTimer) {
            state.inatividadeTimer = setInterval(verificarInatividade, INATIVIDADE_CHECK_MS);
        }
    }

    // Libera no servidor a reserva guardada no cache e descarta o cache local.
    async function liberarReservaEscolas() {
        const cache = lerCacheEscolas();

        limparCacheEscolas(); // limpa antes: um lote em liberação nunca deve ser reaproveitado

        if (!cache || !cache.uf || !cache.username || !obterToken()) return false;

        try {
            await chamarApiEscolas(
                'POST',
                `/api/escolas/uf/${cache.uf}/liberar`,
                { username: cache.username },
                { silencioso: true }
            );
            return true;
        } catch (erro) {
            console.warn(LOG, 'Não foi possível liberar a reserva no servidor:', erro.message);
            return false;
        }
    }

    // Se a seleção atual (tipo/UF/usuário) deixou de corresponder ao lote reservado, libera o lote.
    async function liberarReservaSeMudou({ semUi = false } = {}) {
        const cache = lerCacheEscolas();
        if (!cache) return;

        const mesmaSelecao = ui('wmt-tipo').value === 'ESCOLAS' &&
            ui('wmt-uf').value === cache.uf &&
            ui('wmt-usuario').value.trim() === cache.username;

        if (mesmaSelecao) return;

        if (!semUi) {
            definirCarregando(true);
            setStatus('Liberando reserva anterior...');
        }

        try {
            await liberarReservaEscolas();
        } finally {
            if (!semUi) definirCarregando(false);
        }
    }

    // Ao abrir o WME: se o lote guardado ficou parado além do limite, libera em vez de reaproveitar.
    async function liberarReservaVencida() {
        const cache = lerCacheEscolas();
        if (!cache) return;

        const ultima = Number(cache.ultimaAtividadeEm) || Date.parse(cache.salvoEm) || 0;
        if (Date.now() - ultima < INATIVIDADE_LIMITE_MS) return;

        await liberarReservaEscolas();
        setStatus('Reserva anterior expirou e foi liberada.');
    }

    async function verificarInatividade() {
        const cache = lerCacheEscolas();

        if (!cache || !cache.uf || !cache.username) return;
        if (state.carregando || state.salvando) return;

        const registrada = Number(cache.ultimaAtividadeEm) || 0; // outra aba pode ter atividade mais recente
        const ultima = Math.max(state.ultimaAtividade, registrada);

        if (Date.now() - ultima < INATIVIDADE_LIMITE_MS) {
            // Heartbeat: persiste a atividade para o próximo carregamento saber há quanto tempo o lote está parado.
            if (state.ultimaAtividade > registrada) {
                cache.ultimaAtividadeEm = state.ultimaAtividade;
                store.setJson(KEYS.CACHE, cache);
            }
            return;
        }

        await liberarReservaEscolas();

        if (state.tipoLote === 'ESCOLAS') {
            resetarLote(false);
            setStatus('Reserva liberada após 30 minutos sem uso.');
        }
    }

    /* ====================================================================
     * VERSÃO
     * ==================================================================== */

    async function verificarAtualizacao() {
        const versaoAtual = GM_info.script.version;
        const el = ui('wmt-versao');

        el.innerHTML = '<span style="opacity:.7;">Verificando atualizações...</span>';

        try {
            const resposta = await chamarAppsScript(URLS_API.RANKING, { action: 'checkVersion' });
            const maisRecente = resposta.versao;
            const cmp = compararVersoes(versaoAtual, maisRecente);

            if (cmp >= 0) {
                el.innerHTML = `<span class="wmt-badge" style="background:#4CAF50;">✓ Atualizado (v${esc(versaoAtual)})</span>`;
            } else {
                el.innerHTML = `<a class="wmt-badge" style="background:#f44336;" href="${GREASYFORK_URL}" target="_blank" rel="noopener noreferrer">⚠️ Atualizar (v${esc(maisRecente)})</a>`;
            }
        } catch (e) {
            el.innerHTML = '<span style="color:#f44336;">Erro ao checar versão.</span>';
        }
    }

    /* ====================================================================
     * RANKING
     * ==================================================================== */

    function renderizarTop5(titulo, lista) {
        const linhas = (Array.isArray(lista) ? lista : []).slice(0, 5).map((item, i) => `
            <div class="wmt-top-linha">
                <span>${MEDALHAS[i]} ${esc(item.nome)}</span>
                <b>${esc(item.quantidade)}</b>
            </div>
        `).join('');

        return `<div class="wmt-top"><div class="wmt-top-titulo">${esc(titulo)}</div>${linhas || '<div class="wmt-vazio">Sem dados</div>'}</div>`;
    }

    function renderizarRanking(dados) {
        const progresso = dados.progresso || {};

        RANK_TIPOS.forEach(t => {
            const pct = Math.max(0, Math.min(100, Number(progresso[t.chave]) || 0));
            const linha = state.painel.querySelector(`.wmt-prog-linha[data-tipo="${t.chave}"]`);
            if (!linha) return;
            linha.querySelector('.wmt-prog-pct').textContent = `${pct}%`;
            linha.querySelector('.wmt-bar > i').style.width = `${pct}%`;
        });

        const topPorTipo = dados.topPorTipo || {};
        ui('wmt-ranking-tops').innerHTML = RANK_TIPOS
            .filter(t => RANK_TOP_FIXOS.includes(t.chave) || (topPorTipo[t.chave] || []).length > 0)
            .map(t => renderizarTop5(`${t.emoji} Top 5 · ${t.rotulo}`, topPorTipo[t.chave]))
            .join('');

        ui('wmt-ranking-atualizado').textContent = `Atualizado às ${new Date().toLocaleTimeString('pt-BR')}`;
    }

    async function buscarRanking() {
        if (state.rankingBuscando) return;
        state.rankingBuscando = true;

        try {
            renderizarRanking(await chamarAppsScript(URLS_API.RANKING, { action: 'getRanking' }));
        } catch (erro) {
            ui('wmt-ranking-atualizado').textContent = `Erro ao atualizar: ${erro.message}`;
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
            if (!document.hidden && state.abaAtiva === 'ranking') buscarRanking();
        }, RANKING_REFRESH_MS);
    }

    function mostrarAba(nome) {
        state.abaAtiva = nome;
        const atividade = nome === 'atividade';

        ui('wmt-aba-atividade').style.display = atividade ? 'block' : 'none';
        ui('wmt-aba-ranking').style.display = atividade ? 'none' : 'block';
        ui('wmt-nav-atividade').classList.toggle('ativa', atividade);
        ui('wmt-nav-ranking').classList.toggle('ativa', !atividade);

        if (atividade) {
            pararAutoRefreshRanking();
        } else {
            buscarRanking();
            iniciarAutoRefreshRanking();
        }
    }

    /* ====================================================================
     * EVENTOS
     * ==================================================================== */

    function configurarEventos() {
        const on = (id, evento, fn) => ui(id).addEventListener(evento, fn);

        on('wmt-btn-token', 'click', abrirModalToken);

        on('wmt-btn-remover-token', 'click', () => {
            if (!confirm('Remover o token salvo neste navegador?')) return;
            limparToken();
            atualizarStatusToken();
        });

        on('wmt-tipo', 'change', async () => {
            store.set(KEYS.TIPO, ui('wmt-tipo').value);
            atualizarVisibilidadePorTipo();
            state.loadSeq++; // invalida qualquer carga em andamento
            resetarLote();
            await liberarReservaSeMudou();
            carregarLote();
        });

        on('wmt-uf', 'change', async () => {
            store.set(KEYS.UF, ui('wmt-uf').value);
            state.loadSeq++;
            resetarLote();
            await liberarReservaSeMudou();
            carregarLote();
        });

        on('wmt-usuario', 'change', async () => {
            store.set(KEYS.USUARIO, ui('wmt-usuario').value.trim());

            const havia = !!lerCacheEscolas();
            await liberarReservaSeMudou();
            if (havia && !lerCacheEscolas()) resetarLote(); // o lote antigo deixou de valer

            if (ui('wmt-tipo').value && ui('wmt-uf').value && state.lote.length === 0) carregarLote();
        });

        on('wmt-btn-resolvido', 'click', () => marcarAcao('RESOLVIDO'));
        on('wmt-btn-naolocalizado', 'click', () => marcarAcao('NÃO LOCALIZADO'));
        on('wmt-btn-pular', 'click', () => marcarAcao('PULADO'));
        on('wmt-btn-proximo', 'click', irParaProxima);

        on('wmt-btn-limpar', 'click', async () => {
            if (!confirm('Limpar o lote local de escolas reservadas?')) return;

            const cache = lerCacheEscolas();
            limparCacheEscolas();
            resetarLote();
            setStatus('Reserva local limpa.');

            if (cache && cache.uf && cache.username && obterToken()) {
                try {
                    await chamarApiEscolas('POST', `/api/escolas/uf/${cache.uf}/liberar`, { username: cache.username });
                    setStatus('Reserva local limpa e liberada no servidor.', 'ok');
                } catch (e) {
                    console.warn(LOG, 'Reserva não liberada no servidor:', e.message);
                }
            }
        });

        on('wmt-nav-atividade', 'click', () => mostrarAba('atividade'));
        on('wmt-nav-ranking', 'click', () => mostrarAba('ranking'));
        on('wmt-ranking-refresh', 'click', buscarRanking);
    }

    /* ====================================================================
     * INICIALIZAÇÃO (WME SDK)
     * ==================================================================== */

    async function init() {
        if (state.iniciado) return;
        state.iniciado = true;

        try {
            migrarArmazenamentoLegado();
            injetarEstilos();
            criarCamadaMarcadores();
            await criarPainel();

            popularUFs();
            restaurarPreferencias();
            configurarEventos();
            atualizarVisibilidadePorTipo();
            atualizarStatusToken();
            mostrarAba('atividade');
            setStatus('Selecione o tipo e a UF.');

            iniciarControleInatividade();
            verificarAtualizacao();

            // Lote parado há mais de 30 min (ex.: usuário saiu do WME) é liberado, não reaproveitado.
            await liberarReservaVencida();

            // Retoma automaticamente um lote de escolas reservado em sessão anterior.
            if (ui('wmt-tipo').value === 'ESCOLAS' && ui('wmt-uf').value && lerCacheEscolas()) {
                carregarLote();
            }
        } catch (e) {
            state.iniciado = false;
            console.error(LOG, 'Falha ao montar a interface:', e);
        }
    }

    function iniciarSdk() {
        const win = unsafeWindow;

        if (!win.SDK_INITIALIZED || typeof win.getWmeSdk !== 'function') {
            setTimeout(iniciarSdk, 300);
            return;
        }

        win.SDK_INITIALIZED.then(() => {
            try {
                state.sdk = win.getWmeSdk({ scriptId: SCRIPT_ID, scriptName: SCRIPT_NAME });
            } catch (e) {
                console.error(LOG, 'Falha ao obter o WME SDK:', e);
                return;
            }

            if (state.sdk.State.isReady()) {
                init();
            } else {
                state.sdk.Events.once({ eventName: 'wme-ready' })
                    .then(init)
                    .catch(e => console.error(LOG, 'Erro aguardando wme-ready:', e));
            }
        }).catch(e => console.error(LOG, 'SDK_INITIALIZED rejeitado:', e));
    }

    iniciarSdk();
})();