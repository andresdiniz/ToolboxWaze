// ==UserScript==
// @name         WME Marcador de Tarefas
// @namespace    https://greasyfork.org/pt-BR/users/gabrielcartajena
// @version      1.7.0
// @description  Marcador de Tarefas com suporte a Escolas e token pessoal
// @author       Gabriel Cartajena
// @match        https://www.waze.com/editor*
// @match        https://www.waze.com/*/editor*
// @match        https://beta.waze.com/editor*
// @match        https://beta.waze.com/*/editor*
// @grant        GM_xmlhttpRequest
// @grant        GM_info
// @connect      script.google.com
// @connect      script.googleusercontent.com
// @connect      wazetoolbox.acheireviews.com.br
// @downloadURL  https://update.greasyfork.org/scripts/587740/WME%20Marcador%20de%20Tarefas.user.js
// @updateURL    https://update.greasyfork.org/scripts/587740/WME%20Marcador%20de%20Tarefas.meta.js
// ==/UserScript==

/* global $ */
/* global getWmeSdk */

(function() {
    'use strict';

    console.log("📍 WME Marcador: Script carregado na memória.");

    const URLS_API = {
        RANKING: "https://script.google.com/macros/s/AKfycbyQV4Jqi2T3Oy61y7niE4JwZB0Y7auFE6QSBdaIXdytVVCIBM2z-hk_QMbcQqJ7sApU0g/exec",
        LOMBADAS: "https://script.google.com/macros/s/AKfycbxAbYBimPOLsx6TTXVuQeSxilBI7X48AVzUID3Xvq2ROes88cyS6Em8Du5-HbXIQacC/exec",
        SEMAFOROS: "https://script.google.com/macros/s/AKfycbx6TjgppGT2TeiV6egPG2x58P9CFgv9p4LM3tjUfpWUHE1Q7h8YQuu8x-5OTVEEzZuW/exec",
        FAIXA_ELEVADA: "https://script.google.com/macros/s/AKfycbyszzCgTZCC9qUcyOBql59ktjfoseI-ixxlJzfaRBHD5e3bO2eQt-j6mLEjtZrVzQlK6A/exec",
        ESTREITAMENTO: "https://script.google.com/macros/s/AKfycbyNo5pJ-Q_vkn95FVj0_U0DIuFVxb8nVgjwGLNEGBytI2pB4Al3cG683ZwLsRdOW1vWmA/exec",
        CONFLUENCIA: "https://script.google.com/macros/s/AKfycbx82hpaeRjolTgNIG9AZhkI-W0QFwuNRLFiwfvr3Jc-6ymWL-KqUTB4KdycsYxAO1x5/exec"
    };

    const ESCOLAS_API = {
        BASE: "https://wazetoolbox.acheireviews.com.br",
        TOKEN_STORAGE_KEY: "wme_marcador_escolas_token",
        LOTE: 50,
        CACHE_KEY: "wme_marcador_escolas_reservadas"
    };

    const INATIVIDADE_LIMITE_MS = 30 * 60 * 1000;
    const INATIVIDADE_CHECK_MS = 60 * 1000;

    let ultimaAtividadeEm = Date.now();
    let inatividadeIntervalId = null;

    const LAYER_NAME = "wme_marcador_tarefas";
    const MARKER_ICON = 'https://maps.google.com/mapfiles/ms/icons/red-dot.png';

    const UFS_BRASIL = [
        "AC", "AL", "AP", "AM", "BA", "CE", "DF", "ES", "GO",
        "MA", "MT", "MS", "MG", "PA", "PB", "PR", "PE", "PI",
        "RJ", "RN", "RS", "RO", "RR", "SC", "SP", "SE", "TO"
    ];

    const TIPOS_AVISO_PRECISAO = ["ESTREITAMENTO", "CONFLUENCIA"];

    const RANKING_REFRESH_MS = 20000;
    const MEDALHAS = ['🥇', '🥈', '🥉', '4º', '5º'];
    const TIPOS_RANKING = [
        { chave: 'lombadas', titulo: '🚧 Top 5 · Lombadas', cor: '#4CAF50' },
        { chave: 'semaforos', titulo: '🚦 Top 5 · Semáforos', cor: '#FF9800' },
        { chave: 'faixaElevada', titulo: '🚶 Top 5 · Faixa Elevada', cor: '#2196F3' }
    ];

    let wmeSdk;
    let currentBatch = [];
    let currentIndex = -1;
    let painelRaiz = null;
    let abaAtiva = 'atividade';
    let rankingIntervalId = null;
    let scriptInicializado = false;

    function $ui(seletor) {
        return $(painelRaiz).find(seletor);
    }

    function obterTokenEscolas() {
        return localStorage.getItem(ESCOLAS_API.TOKEN_STORAGE_KEY) || "";
    }

    function salvarTokenEscolas(token) {
        localStorage.setItem(ESCOLAS_API.TOKEN_STORAGE_KEY, token.trim());
    }

    function limparTokenEscolas() {
        localStorage.removeItem(ESCOLAS_API.TOKEN_STORAGE_KEY);
    }

    function abrirModalToken() {
        const modal = `
            <div id="wme-token-modal" style="position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:999999; display:flex; align-items:center; justify-content:center;">
                <div style="background:#fff; width:420px; max-width:92%; border-radius:10px; padding:20px; font-family:Arial, sans-serif;">
                    <h3 style="margin:0 0 12px;">🔑 Gerar e salvar seu token</h3>
                    <ol style="margin:0 0 16px; padding-left:20px; font-size:13px; line-height:1.6; color:#333;">
                        <li>Clique em <b>Abrir página do token</b>.</li>
                        <li>Faça login no ToolboxWaze, se necessário.</li>
                        <li>Clique em <b>Gerar novo token</b>.</li>
                        <li>Copie o token gerado.</li>
                        <li>Cole no campo abaixo e clique em <b>Salvar token</b>.</li>
                    </ol>

                    <a href="https://wazetoolbox.acheireviews.com.br/profile/api-token" target="_blank" style="display:block; text-align:center; background:#2196F3; color:#fff; padding:10px; border-radius:6px; text-decoration:none; font-weight:bold; margin-bottom:14px;">Abrir página do token</a>

                    <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:6px;">Cole seu token:</label>
                    <input type="password" id="wme-token-modal-input" placeholder="Cole aqui o token" style="width:100%; padding:9px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box;">

                    <div style="display:flex; gap:8px; margin-top:14px;">
                        <button id="wme-token-modal-salvar" style="flex:1; background:#4CAF50; color:#fff; border:none; padding:10px; border-radius:6px; font-weight:bold;">Salvar token</button>
                        <button id="wme-token-modal-fechar" style="flex:1; background:#607D8B; color:#fff; border:none; padding:10px; border-radius:6px; font-weight:bold;">Fechar</button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modal);

        $('#wme-token-modal-salvar').on('click', function() {
            const token = $('#wme-token-modal-input').val().trim();

            if (!token) {
                alert('Cole o token gerado no site.');
                return;
            }

            salvarTokenEscolas(token);
            $('#wme-token-modal').remove();
            $ui('#wme-tarefas-token-status').text('Token salvo neste navegador.');
        });

        $('#wme-token-modal-fechar').on('click', function() {
            $('#wme-token-modal').remove();
        });
    }

    function initializeScriptSetup() {
        if (typeof getWmeSdk === 'undefined') {
            setTimeout(initializeScriptSetup, 500);
            return;
        }

        try {
            wmeSdk = getWmeSdk({
                scriptId: 'wme-marcador-tarefas',
                scriptName: 'WME Marcador de Tarefas'
            });

            if (wmeSdk.State.isReady()) {
                init();
            } else {
                wmeSdk.Events.once({ eventName: 'wme-ready' })
                    .then(init)
                    .catch(e => console.error("📍 WME Marcador: Erro na promessa wme-ready:", e));
            }
        } catch (e) {
            console.error("📍 WME Marcador: Falha ao inicializar o WME SDK:", e);
        }
    }

    async function init() {
        if (scriptInicializado) return;
        scriptInicializado = true;

        try {
            criarCamadaMarcadores();
        } catch (e) {
            console.error("📍 WME Marcador: Aviso ao criar camada:", e);
        }

        await criarPainelUI();

        if (painelRaiz) {
            configurarEventosPainel();
            atualizarUFsDisponiveis();
            mostrarAba('atividade');
            atualizarStatusToken();

            ['click', 'keydown', 'mousemove', 'touchstart'].forEach(evento => {
                document.addEventListener(evento, registrarAtividade, { passive: true });
            });

            iniciarControleInatividade();
            verificarAtualizacao();
        }
    }

    function atualizarStatusToken() {
        const token = obterTokenEscolas();
        $ui('#wme-tarefas-token-status').text(token ? 'Token salvo neste navegador.' : 'Nenhum token salvo.');
    }

    function criarCamadaMarcadores() {
        wmeSdk.Map.addLayer({
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
    }

    function chamarAPI(urlAlvo, payload, onSuccess, onError) {
        GM_xmlhttpRequest({
            method: "POST",
            url: urlAlvo,
            headers: { "Content-Type": "application/json" },
            data: JSON.stringify(payload),
            onload: function(res) {
                try {
                    const resposta = JSON.parse(res.responseText);

                    if (resposta.resultado === "sucesso") {
                        onSuccess(resposta);
                    } else {
                        if (onError) onError(resposta.mensagem);
                    }
                } catch(e) {
                    if (onError) onError("Erro ao ler servidor.");
                }
            },
            onerror: function() {
                if (onError) onError("Erro de conexão.");
            }
        });
    }

    function chamarApiEscolas(metodo, caminho, payload) {
        return new Promise((resolve, reject) => {
            const token = obterTokenEscolas();

            if (!token) {
                abrirModalToken();
                reject(new Error("Informe o Token da API antes de usar Escolas."));
                return;
            }

            GM_xmlhttpRequest({
                method: metodo,
                url: ESCOLAS_API.BASE + caminho,
                headers: {
                    "X-API-TOKEN": token,
                    "Content-Type": "application/json",
                    "Accept": "application/json"
                },
                data: payload ? JSON.stringify(payload) : undefined,
                timeout: 30000,
                onload: function(res) {
                    try {
                        const dados = JSON.parse(res.responseText);

                        if (res.status >= 400) {
                            reject(new Error(dados.error || `Erro HTTP ${res.status}`));
                            return;
                        }

                        resolve(dados);
                    } catch (e) {
                        reject(new Error("Resposta inválida da API de Escolas."));
                    }
                },
                onerror: function() {
                    reject(new Error("Falha de conexão com a API de Escolas."));
                },
                ontimeout: function() {
                    reject(new Error("Tempo limite ao chamar a API de Escolas."));
                }
            });
        });
    }

    function salvarCacheEscolas(dados) {
        localStorage.setItem(
            ESCOLAS_API.CACHE_KEY,
            JSON.stringify({
                uf: dados.uf,
                username: dados.username,
                escolas: dados.escolas,
                salvoEm: new Date().toISOString()
            })
        );
    }

    function lerCacheEscolas() {
        try {
            const bruto = localStorage.getItem(ESCOLAS_API.CACHE_KEY);
            return bruto ? JSON.parse(bruto) : null;
        } catch (e) {
            return null;
        }
    }

    function limparCacheEscolas() {
        localStorage.removeItem(ESCOLAS_API.CACHE_KEY);
    }

    function converterEscolaParaTarefa(escola) {
        const lat = parseFloat(escola.latitude);
        const lon = parseFloat(escola.longitude);

        return {
            id: escola.id,
            linhaDoc: escola.id,
            tipo: "ESCOLAS",
            escola: escola.escola,
            codigoInep: escola.codigo_inep,
            municipio: escola.municipio,
            endereco: escola.endereco,
            coords: `${lat},${lon}`,
            linkWaze: escola.link_waze,
            linkAreaEscolar: escola.link_area_escolar,
            status: escola.status,
            reservadoPara: escola.reservado_para
        };
    }

    function registrarAtividade() {
        ultimaAtividadeEm = Date.now();
    }

    function iniciarControleInatividade() {
        if (inatividadeIntervalId) return;
        inatividadeIntervalId = setInterval(verificarInatividade, INATIVIDADE_CHECK_MS);
    }

    async function verificarInatividade() {
        const cache = lerCacheEscolas();

        if (!cache || !cache.uf || !cache.username) return;

        if (Date.now() - ultimaAtividadeEm < INATIVIDADE_LIMITE_MS) return;

        try {
            await chamarApiEscolas(
                "POST",
                `/api/escolas/uf/${cache.uf}/liberar`,
                { username: cache.username }
            );
        } catch (erro) {
            console.warn("📍 WME Marcador: Não foi possível liberar a reserva por inatividade:", erro.message);
        }

        limparCacheEscolas();
        currentBatch = [];
        currentIndex = 0;
        atualizarNavegacao();
        $ui('#wme-tarefas-status').text("Reserva liberada após 30 minutos sem uso.");
    }

    function obterUrlPorTipo(tipo) {
        if (tipo === "SEMAFOROS") return URLS_API.SEMAFOROS;
        if (tipo === "FAIXA_ELEVADA") return URLS_API.FAIXA_ELEVADA;
        if (tipo === "ESTREITAMENTO") return URLS_API.ESTREITAMENTO;
        if (tipo === "CONFLUENCIA") return URLS_API.CONFLUENCIA;
        return URLS_API.LOMBADAS;
    }

    function verificarAtualizacao() {
        const versaoAtual = GM_info.script.version;
        const $container = $ui('#wme-update-status');

        $container.html('<span style="color: #888;">Verificando atualizações...</span>');

        chamarAPI(URLS_API.RANKING, { action: "checkVersion" },
            function(resposta) {
                const versaoMaisRecente = resposta.versao;

                if (versaoAtual === versaoMaisRecente) {
                    $container.html(`<span style="background: #4CAF50; color: white; padding: 4px 10px; border-radius: 12px; font-weight: bold;">✓ Atualizado (v${versaoAtual})</span>`);
                } else {
                    $container.html(`<a href="https://greasyfork.org/pt-BR/scripts/587740-wme-marcador-de-tarefas" target="_blank" style="background: #f44336; color: white; padding: 4px 10px; border-radius: 12px; font-weight: bold; text-decoration: none;">⚠️ Atualizar (v${versaoMaisRecente})</a>`);
                }
            },
            function() {
                $container.html('<span style="color: #f44336;">Erro ao checar versão.</span>');
            }
        );
    }

    async function carregarTarefasLote() {
        const nomeUsuario = $ui('#wme-tarefas-usuario').val().trim();

        if (!nomeUsuario) {
            alert("⚠️ Por favor, digite o seu Nome de Usuário antes de carregar ou editar as tarefas!");
            return;
        }

        const tipo = $ui('#wme-tarefas-tipo').val();
        const uf = $ui('#wme-tarefas-uf').val();

        if (!tipo || !uf) return;

        $ui('#wme-tarefas-status').text("Carregando tarefas...");
        $ui('#wme-tarefas-uf, #wme-tarefas-tipo, #wme-tarefas-usuario').prop('disabled', true);
        desabilitarAcoes();
        wmeSdk.Map.removeAllFeaturesFromLayer({ layerName: LAYER_NAME });

        try {
            if (tipo === "ESCOLAS") {
                const cache = lerCacheEscolas();
                const cacheValido = cache && cache.uf === uf && cache.username === nomeUsuario && Array.isArray(cache.escolas) && cache.escolas.length > 0;
                let escolas;

                if (cacheValido) {
                    escolas = cache.escolas;
                    $ui('#wme-tarefas-status').text("Lote recuperado do cache local.");
                } else {
                    const resposta = await chamarApiEscolas(
                        "POST",
                        `/api/escolas/uf/${uf}/reservar`,
                        { username: nomeUsuario, limite: ESCOLAS_API.LOTE }
                    );

                    escolas = (resposta.escolas || []).slice(0, ESCOLAS_API.LOTE);

                    if (escolas.length === 0) {
                        currentBatch = [];
                        currentIndex = 0;
                        atualizarNavegacao();
                        $ui('#wme-tarefas-status').text("Nenhuma escola disponível para reserva nesta UF.");
                        return;
                    }

                    salvarCacheEscolas({ uf, username: nomeUsuario, escolas });
                }

                currentBatch = escolas.map(converterEscolaParaTarefa);
                currentIndex = 0;
                $ui('#wme-tarefas-uf, #wme-tarefas-tipo, #wme-tarefas-usuario').prop('disabled', false);
                atualizarNavegacao();
                return;
            }

            const urlAlvo = obterUrlPorTipo(tipo);

            chamarAPI(urlAlvo, { action: "getTarefas", tipo, uf, usuario: nomeUsuario },
                function(resposta) {
                    currentBatch = resposta.tarefas;
                    currentIndex = 0;
                    $ui('#wme-tarefas-uf, #wme-tarefas-tipo, #wme-tarefas-usuario').prop('disabled', false);

                    const total = resposta.total || 0;
                    const concluidos = resposta.concluidos || 0;
                    const perc = total > 0 ? Math.round((concluidos / total) * 100) : 0;
                    $ui('#wme-tarefas-progresso').text(`${perc}% concluídos (${concluidos}/${total})`);

                    if (currentBatch.length === 0) {
                        $ui('#wme-tarefas-status').text("Todas concluídas!");
                        $ui('#wme-tarefas-contador').text("0/0");
                    } else {
                        atualizarNavegacao();
                    }
                },
                function(erroMensagem) {
                    $ui('#wme-tarefas-status').text(erroMensagem);
                    $ui('#wme-tarefas-uf, #wme-tarefas-tipo, #wme-tarefas-usuario').prop('disabled', false);
                }
            );
        } catch (erro) {
            $ui('#wme-tarefas-status').text("Erro: " + erro.message);
            $ui('#wme-tarefas-uf, #wme-tarefas-tipo, #wme-tarefas-usuario').prop('disabled', false);
        }
    }

    function popularDropdownUFs(ufs) {
        const select = $ui('#wme-tarefas-uf');
        select.empty().append('<option value="">Selecione uma UF</option>');
        [...ufs].sort().forEach(uf => select.append(`<option value="${uf}">${uf}</option>`));
        select.prop('disabled', false);
    }

    function atualizarUFsDisponiveis() {
        const tipo = $ui('#wme-tarefas-tipo').val();
        const $aviso = $ui('#wme-tarefas-aviso-beta');
        const ufAtual = $ui('#wme-tarefas-uf').val();

        popularDropdownUFs(UFS_BRASIL);

        if (ufAtual) {
            $ui('#wme-tarefas-uf').val(ufAtual);
        }

        if (TIPOS_AVISO_PRECISAO.includes(tipo)) {
            $aviso.show();
        } else {
            $aviso.hide();
        }
    }

    function atualizarNavegacao() {
        if (currentBatch.length === 0) {
            $ui('#wme-tarefas-contador').text("0/0");
            desabilitarAcoes();
            wmeSdk.Map.removeAllFeaturesFromLayer({ layerName: LAYER_NAME });
            return;
        }

        $ui('#wme-tarefas-contador').text(`${currentIndex + 1}/${currentBatch.length}`);
        $ui('#wme-tarefas-status').text("Aguardando ação...");
        $ui('#wme-tarefas-btn-resolvido, #wme-tarefas-btn-naolocalizado').prop('disabled', false);
        $ui('#wme-tarefas-btn-proximo').prop('disabled', true);

        irParaLocalAtual();
    }

    function irParaLocalAtual() {
        const item = currentBatch[currentIndex];

        if (!item || !item.coords) {
            $ui('#wme-tarefas-status').text("Tarefa sem coordenadas.");
            return;
        }

        registrarAtividade();

        const [latStr, lonStr] = item.coords.split(',');
        const lat = parseFloat(latStr);
        const lon = parseFloat(lonStr);

        if (Number.isNaN(lat) || Number.isNaN(lon)) {
            $ui('#wme-tarefas-status').text("Coordenadas inválidas nesta tarefa.");
            return;
        }

        wmeSdk.Map.setMapCenter({ lonLat: { lon, lat }, zoomLevel: 19 });
        wmeSdk.Map.removeAllFeaturesFromLayer({ layerName: LAYER_NAME });
        wmeSdk.Map.addFeatureToLayer({
            layerName: LAYER_NAME,
            feature: {
                id: `tarefa-${item.linhaDoc}`,
                type: "Feature",
                geometry: { type: "Point", coordinates: [lon, lat] },
                properties: {}
            }
        });
    }

    async function marcarAcao(tipoAcao) {
        const nomeUsuario = $ui('#wme-tarefas-usuario').val().trim();

        if (!nomeUsuario) {
            alert("⚠️ Por favor, digite o seu Nome de Usuário antes de salvar!");
            return;
        }

        const item = currentBatch[currentIndex];

        if (!item) return;

        registrarAtividade();
        $ui('#wme-tarefas-status').text("Salvando...");
        $ui('#wme-tarefas-btn-resolvido, #wme-tarefas-btn-naolocalizado').prop('disabled', true);

        try {
            if (item.tipo === "ESCOLAS") {
                const statusApi = {
                    "RESOLVIDO": "confirmado",
                    "NÃO LOCALIZADO": "nao_localizado",
                    "PULADO": "pulado"
                }[tipoAcao];

                if (!statusApi) throw new Error("Ação inválida para Escolas.");

                await chamarApiEscolas(
                    "PATCH",
                    `/api/escolas/${item.id}/status`,
                    { status: statusApi, username: nomeUsuario }
                );

                const cache = lerCacheEscolas();

                if (cache && Array.isArray(cache.escolas)) {
                    cache.escolas = cache.escolas.filter(escola => escola.id !== item.id);

                    if (cache.escolas.length === 0) {
                        limparCacheEscolas();
                    } else {
                        salvarCacheEscolas(cache);
                    }
                }

                $ui('#wme-tarefas-status').text("Salvo!");
                $ui('#wme-tarefas-btn-proximo').prop('disabled', false);
                return;
            }

            const tipoTarefa = $ui('#wme-tarefas-tipo').val();
            const urlAlvo = obterUrlPorTipo(tipoTarefa);

            chamarAPI(urlAlvo, { action: "salvar", tipo: tipoTarefa, linha: item.linhaDoc, status: tipoAcao, usuario: nomeUsuario },
                function() {
                    $ui('#wme-tarefas-status').text("Salvo!");
                    $ui('#wme-tarefas-btn-proximo').prop('disabled', false);
                },
                function(erroMensagem) {
                    $ui('#wme-tarefas-status').text("Erro: " + erroMensagem);
                    $ui('#wme-tarefas-btn-resolvido, #wme-tarefas-btn-naolocalizado').prop('disabled', false);
                }
            );
        } catch (erro) {
            $ui('#wme-tarefas-status').text("Erro: " + erro.message);
            $ui('#wme-tarefas-btn-resolvido, #wme-tarefas-btn-naolocalizado').prop('disabled', false);
        }
    }

    async function criarPainelUI() {
        const conteudoPainel = `
            <div style="padding: 10px; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;">
                <h4 style="margin-top: 0; margin-bottom: 12px; font-weight: bold;">Inspetor de Tarefas</h4>
                <div id="wme-update-status" style="margin-bottom: 15px; text-align: center; font-size: 11px;"></div>

                <div style="display: flex; margin-bottom: 14px; border-bottom: 2px solid #e0e0e0;">
                    <button id="wme-nav-atividade" style="flex: 1; background: none; border: none; border-bottom: 3px solid #2196F3; padding: 8px 0; font-weight: bold; font-size: 12px; color: #2196F3;">📋 Atividade</button>
                    <button id="wme-nav-ranking" style="flex: 1; background: none; border: none; border-bottom: 3px solid transparent; padding: 8px 0; font-weight: bold; font-size: 12px; color: #888;">🏆 Ranking</button>
                </div>

                <div id="wme-aba-atividade">
                    <div style="margin-bottom: 10px;">
                        <label style="font-size: 12px; display:block; margin-bottom: 6px; font-weight: 600;">Token da API <span style="color:red;">*</span>:</label>
                        <div id="wme-tarefas-token-status" style="font-size:11px; color:#666; margin-bottom:6px;">Verificando...</div>
                        <div style="display:flex; gap:8px;">
                            <button id="wme-tarefas-btn-token" style="flex:1; background:#2196F3; color:#fff; border:none; padding:8px; border-radius:4px; font-weight:bold;">🔑 Token da API</button>
                            <button id="wme-tarefas-btn-remover-token" style="flex:1; background:#f44336; color:#fff; border:none; padding:8px; border-radius:4px; font-weight:bold;">Remover</button>
                        </div>
                    </div>

                    <div style="margin-bottom: 10px;">
                        <label style="font-size: 12px; display:block; margin-bottom: 6px; font-weight: 600;">Nome do Usuário <span style="color:red;">*</span>:</label>
                        <input type="text" id="wme-tarefas-usuario" placeholder="Seu nome (obrigatório)" style="width: 100%; padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
                    </div>

                    <div style="margin-bottom: 10px;">
                        <label style="font-size: 12px; display:block; margin-bottom: 6px; font-weight: 600;">Tipo de Tarefa:</label>
                        <select id="wme-tarefas-tipo" style="width: 100%; padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
                            <option value="LOMBADAS">Lombadas</option>
                            <option value="SEMAFOROS">Semáforos</option>
                            <option value="FAIXA_ELEVADA">Faixa Elevada</option>
                            <option value="ESTREITAMENTO">Estreitamento</option>
                            <option value="CONFLUENCIA">Confluência</option>
                            <option value="ESCOLAS">Escolas</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 6px;">
                        <label style="font-size: 12px; display:block; margin-bottom: 6px; font-weight: 600;">Estado (UF):</label>
                        <select id="wme-tarefas-uf" style="width: 100%; padding: 6px; border-radius: 4px; border: 1px solid #ccc;"></select>
                    </div>

                    <div id="wme-tarefas-aviso-beta" style="display:none; margin-bottom: 12px; font-size: 11px; color: #E65100; background: #FFF3E0; border: 1px solid #FFE0B2; border-radius: 4px; padding: 6px 8px;">
                        📍 Localização da sinalização. Uma análise mais precisa é necessária.
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; background: #f5f5f5; padding: 8px; border-radius: 4px;">
                        <span id="wme-tarefas-contador" style="font-weight: bold;">0/0</span>
                        <span id="wme-tarefas-status" style="font-size: 11px; color: #666; font-weight: bold;">Inicializando...</span>
                    </div>

                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                        <button id="wme-tarefas-btn-resolvido" style="flex: 1; background: #4CAF50; color: white; border: none; padding: 10px 5px; border-radius: 4px; font-weight: bold;" disabled>✓ RESOLVIDO</button>
                        <button id="wme-tarefas-btn-naolocalizado" style="flex: 1; background: #f44336; color: white; border: none; padding: 10px 5px; border-radius: 4px; font-weight: bold;" disabled>❌ Ñ ACHADO</button>
                    </div>

                    <button id="wme-tarefas-btn-proximo" style="width: 100%; background: #2196F3; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold;" disabled>PRÓXIMO ➡</button>

                    <button id="wme-tarefas-btn-limpar-cache" style="width: 100%; margin-top: 8px; background: #607D8B; color: white; border: none; padding: 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">🧹 Limpar reserva local</button>

                    <div id="wme-tarefas-progresso" style="text-align: center; margin-top: 10px; font-size: 12px; font-weight: bold;"></div>
                </div>

                <div id="wme-aba-ranking" style="display: none;">
                    <div style="font-weight: bold; margin-bottom: 8px;">📊 Progresso Geral por Tipo</div>
                    <div id="wme-ranking-tipos"></div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                        <span id="wme-ranking-atualizado" style="font-size:10px; color:#888;"></span>
                        <button id="wme-ranking-refresh" style="background:#607D8B; color:white; border:none; padding:6px 10px; border-radius:4px;">🔄 Atualizar</button>
                    </div>
                </div>
            </div>
        `;

        try {
            const { tabLabel, tabPane } = await wmeSdk.Sidebar.registerScriptTab();
            tabLabel.innerHTML = '<span title="Marcador de Tarefas" style="font-size: 15px;">📍</span>';

            const container = document.createElement('div');
            container.id = 'wme-tarefas-container';
            container.innerHTML = conteudoPainel;
            tabPane.appendChild(container);
            painelRaiz = container;
        } catch (err) {
            const container = document.createElement('div');
            container.id = 'wme-tarefas-container';
            container.style.border = "1px solid #ccc";
            container.style.marginTop = "10px";
            container.innerHTML = conteudoPainel;
            $('#user-info').append(container);
            painelRaiz = container;
        }
    }

    function tentarCarregarTarefas() {
        const ufSelecionada = $ui('#wme-tarefas-uf').val();
        const tipoSelecionado = $ui('#wme-tarefas-tipo').val();

        if (ufSelecionada && tipoSelecionado) {
            carregarTarefasLote();
        } else {
            currentBatch = [];
            currentIndex = 0;
            atualizarNavegacao();
            $ui('#wme-tarefas-status').text("Selecione o tipo e a UF.");
        }
    }

    function configurarEventosPainel() {
        $ui('#wme-tarefas-btn-token').on('click', abrirModalToken);

        $ui('#wme-tarefas-btn-remover-token').on('click', function() {
            if (!confirm('Remover o token salvo neste navegador?')) return;

            limparTokenEscolas();
            atualizarStatusToken();
        });

        $ui('#wme-tarefas-tipo').on('change', function() {
            atualizarUFsDisponiveis();
            tentarCarregarTarefas();
        });

        $ui('#wme-tarefas-uf').on('change', function() {
            tentarCarregarTarefas();
        });

        $ui('#wme-tarefas-btn-resolvido').on('click', () => marcarAcao("RESOLVIDO"));
        $ui('#wme-tarefas-btn-naolocalizado').on('click', () => marcarAcao("NÃO LOCALIZADO"));

        $ui('#wme-tarefas-btn-proximo').on('click', () => {
            if (currentIndex < currentBatch.length - 1) {
                currentIndex++;
                atualizarNavegacao();
            } else {
                carregarTarefasLote();
            }
        });

        $ui('#wme-tarefas-btn-limpar-cache').on('click', function() {
            if (!confirm("Limpar o lote local de escolas reservadas?")) return;

            limparCacheEscolas();
            currentBatch = [];
            currentIndex = 0;
            atualizarNavegacao();
            $ui('#wme-tarefas-status').text("Reserva local limpa.");
        });

        $ui('#wme-nav-atividade').on('click', () => mostrarAba('atividade'));
        $ui('#wme-nav-ranking').on('click', () => mostrarAba('ranking'));
        $ui('#wme-ranking-refresh').on('click', () => buscarRanking());
    }

    function mostrarAba(nome) {
        abaAtiva = nome;
        const ativaAtividade = nome === 'atividade';

        $ui('#wme-aba-atividade').toggle(ativaAtividade);
        $ui('#wme-aba-ranking').toggle(!ativaAtividade);

        if (ativaAtividade) {
            pararAutoRefreshRanking();
        } else {
            buscarRanking();
            iniciarAutoRefreshRanking();
        }
    }

    function iniciarAutoRefreshRanking() {
        pararAutoRefreshRanking();
        rankingIntervalId = setInterval(buscarRanking, RANKING_REFRESH_MS);
    }

    function pararAutoRefreshRanking() {
        if (rankingIntervalId) {
            clearInterval(rankingIntervalId);
            rankingIntervalId = null;
        }
    }

    function buscarRanking() {
        chamarAPI(URLS_API.RANKING, { action: "getRanking" },
            function(resposta) {
                renderizarRanking(resposta);
            },
            function(erroMensagem) {
                $ui('#wme-ranking-atualizado').text(`Erro ao atualizar: ${erroMensagem}`);
            }
        );
    }

    function renderizarTop5(titulo, lista) {
        const linhas = (lista || []).map((item, i) => `
            <div style="display:flex; justify-content:space-between; padding:3px 0; font-size:11px;">
                <span>${MEDALHAS[i]} ${item.nome}</span>
                <span style="font-weight:bold;">${item.quantidade}</span>
            </div>
        `).join('');

        return `<div style="background:#fff; border:1px solid #e0e0e0; border-radius:4px; padding:8px 10px; margin-bottom:8px;"><div style="font-weight:bold; font-size:12px;">${titulo}</div>${linhas || '<div style="font-size:11px; color:#999;">Sem dados</div>'}</div>`;
    }

    function renderizarRanking(dados) {
        const progresso = dados.progresso || {};

        ['lombadas', 'semaforos', 'faixaElevada', 'estreitamento', 'confluencia'].forEach(tipo => {
            const pct = progresso[tipo] || 0;
            const linha = $ui(`.wme-progress-row[data-tipo="${tipo}"]`);
            linha.find('.wme-progress-pct').text(`${pct}%`);
            linha.find('.wme-progress-fill').css('width', `${pct}%`);
        });

        const topPorTipo = dados.topPorTipo || {};
        $ui('#wme-ranking-tipos').html(
            TIPOS_RANKING.map(t => renderizarTop5(t.titulo, topPorTipo[t.chave])).join('')
        );

        $ui('#wme-ranking-atualizado').text(`Atualizado às ${new Date().toLocaleTimeString('pt-BR')}`);
    }

    function desabilitarAcoes() {
        $ui('#wme-tarefas-btn-resolvido, #wme-tarefas-btn-naolocalizado, #wme-tarefas-btn-proximo').prop('disabled', true);
    }

    initializeScriptSetup();
})();
