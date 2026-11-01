# Integração: Escolas no WME Marcador de Tarefas

## Recomendação de desempenho

Use **reserva em lote de 50 escolas**, e não uma por vez.

| Estratégia | Vantagem | Desvantagem |
|---|---|---|
| Reservar 1 por vez | Menor risco de reserva parada | Muitas requisições e mais lentidão |
| Reservar 50 e salvar no `localStorage` | Rápido, menos requisições e funciona bem offline | Escolas podem ficar reservadas se o usuário não concluir |
| Reservar 100 | Menos chamadas ainda | Lote maior parado em caso de abandono |

**Recomendação:** reservar **50**, salvar no `localStorage` e enviar apenas a ação final de cada escola para a API.

## Inatividade de 30 minutos

O userscript deve acompanhar a última interação do usuário. Após **30 minutos sem uso**, ele deve:

1. Chamar `POST /api/escolas/uf/{UF}/liberar`.
2. Liberar apenas as escolas com `status = reservado` e `reservado_para = username`.
3. Limpar o `localStorage`.
4. Limpar a lista local e os marcadores do mapa.

Adicione estas constantes:

```js
const INATIVIDADE_LIMITE_MS = 30 * 60 * 1000; // 30 minutos
const INATIVIDADE_CHECK_MS = 60 * 1000; // verifica a cada 1 minuto

let ultimaAtividadeEm = Date.now();
let inatividadeIntervalId = null;
```

Adicione estas funções:

```js
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

    const tempoSemUso = Date.now() - ultimaAtividadeEm;

    if (tempoSemUso < INATIVIDADE_LIMITE_MS) return;

    try {
        await chamarApiEscolas(
            "POST",
            `/api/escolas/uf/${cache.uf}/liberar`,
            { username: cache.username }
        );

        console.log("📍 WME Marcador: Reserva de escolas liberada por inatividade.");
    } catch (erro) {
        console.warn("📍 WME Marcador: Não foi possível liberar a reserva por inatividade:", erro.message);
    }

    limparCacheEscolas();
    currentBatch = [];
    currentIndex = 0;
    atualizarNavegacao();
    $ui('#wme-tarefas-status').text("Reserva liberada após 30 minutos sem uso.");
}
```

No final da função `init()`, depois de `configurarEventosPainel()`, adicione:

```js
['click', 'keydown', 'mousemove', 'touchstart'].forEach(evento => {
    document.addEventListener(evento, registrarAtividade, { passive: true });
});

iniciarControleInatividade();
```

## Configuração

Adicione no início do userscript, depois de `URLS_API`:

```js
const ESCOLAS_API = {
    BASE: "https://wazetoolbox.acheireviews.com.br",
    TOKEN: "SEU_TOKEN_DE_API",
    LOTE: 50,
    CACHE_KEY: "wme_marcador_escolas_reservadas"
};
```

Adicione também em `@connect`:

```js
// @connect      wazetoolbox.acheireviews.com.br
```

## Adicionar o tipo Escolas

No `<select id="wme-tarefas-tipo">`, inclua:

```html
<option value="ESCOLAS">Escolas</option>
```

## Funções da API de Escolas

Adicione estas funções ao userscript:

```js
function chamarApiEscolas(metodo, caminho, payload) {
    return new Promise((resolve, reject) => {
        GM_xmlhttpRequest({
            method: metodo,
            url: ESCOLAS_API.BASE + caminho,
            headers: {
                "X-API-TOKEN": ESCOLAS_API.TOKEN,
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
```

## Carregar lote de escolas

Substitua o início de `carregarTarefasLote()` por esta versão:

```js
async function carregarTarefasLote() {
    const nomeUsuario = $ui('#wme-tarefas-usuario').val().trim();

    if (!nomeUsuario) {
        alert("⚠️ Por favor, digite o seu Nome de Usuário antes de carregar ou editar as tarefas!");
        $ui('#wme-tarefas-uf').val("");
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
            const cacheValido =
                cache &&
                cache.uf === uf &&
                cache.username === nomeUsuario &&
                Array.isArray(cache.escolas) &&
                cache.escolas.length > 0;

            let escolas;

            if (cacheValido) {
                escolas = cache.escolas;
                $ui('#wme-tarefas-status').text("Lote recuperado do cache local.");
            } else {
                const resposta = await chamarApiEscolas(
                    "POST",
                    `/api/escolas/uf/${uf}/reservar`,
                    {
                        username: nomeUsuario,
                        limite: ESCOLAS_API.LOTE
                    }
                );

                escolas = (resposta.escolas || []).slice(0, ESCOLAS_API.LOTE);

                if (escolas.length === 0) {
                    currentBatch = [];
                    currentIndex = 0;
                    atualizarNavegacao();
                    $ui('#wme-tarefas-status').text("Nenhuma escola disponível para reserva nesta UF.");
                    return;
                }

                salvarCacheEscolas({
                    uf,
                    username: nomeUsuario,
                    escolas
                });
            }

            currentBatch = escolas.map(converterEscolaParaTarefa);
            currentIndex = 0;

            $ui('#wme-tarefas-uf, #wme-tarefas-tipo, #wme-tarefas-usuario').prop('disabled', false);
            atualizarNavegacao();
            return;
        }

        const urlAlvo = obterUrlPorTipo(tipo);

        chamarAPI(urlAlvo, {
            action: "getTarefas",
            tipo,
            uf,
            usuario: nomeUsuario
        }, function(resposta) {
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
        }, function(erroMensagem) {
            $ui('#wme-tarefas-status').text(erroMensagem);
            $ui('#wme-tarefas-uf, #wme-tarefas-tipo, #wme-tarefas-usuario').prop('disabled', false);
        });
    } catch (erro) {
        $ui('#wme-tarefas-status').text("Erro: " + erro.message);
        $ui('#wme-tarefas-uf, #wme-tarefas-tipo, #wme-tarefas-usuario').prop('disabled', false);
    }
}
```

## Enviar ação individual

Substitua `marcarAcao()` por:

```js
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

            if (!statusApi) {
                throw new Error("Ação inválida para Escolas.");
            }

            await chamarApiEscolas(
                "PATCH",
                `/api/escolas/${item.id}/status`,
                {
                    status: statusApi,
                    username: nomeUsuario
                }
            );

            const cache = lerCacheEscolas();

            if (cache && Array.isArray(cache.escolas)) {
                cache.escolas = cache.escolas.filter(
                    escola => escola.id !== item.id
                );

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

        chamarAPI(urlAlvo, {
            action: "salvar",
            tipo: tipoTarefa,
            linha: item.linhaDoc,
            status: tipoAcao,
            usuario: nomeUsuario
        }, function() {
            $ui('#wme-tarefas-status').text("Salvo!");
            $ui('#wme-tarefas-btn-proximo').prop('disabled', false);
        }, function(erroMensagem) {
            $ui('#wme-tarefas-status').text("Erro: " + erroMensagem);
            $ui('#wme-tarefas-btn-resolvido, #wme-tarefas-btn-naolocalizado').prop('disabled', false);
        });
    } catch (erro) {
        $ui('#wme-tarefas-status').text("Erro: " + erro.message);
        $ui('#wme-tarefas-btn-resolvido, #wme-tarefas-btn-naolocalizado').prop('disabled', false);
    }
}
```

## Melhorar a navegação

Para mostrar informações da escola, substitua `irParaLocalAtual()` por:

```js
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
        $ui('#wme-tarefas-status').text("Coordenadas inválidas nesta escola.");
        return;
    }

    if (item.tipo === "ESCOLAS") {
        console.log("📍 Escola atual:", item);
    }

    wmeSdk.Map.setMapCenter({ lonLat: { lon, lat }, zoomLevel: 19 });
    wmeSdk.Map.removeAllFeaturesFromLayer({ layerName: LAYER_NAME });
    wmeSdk.Map.addFeatureToLayer({
        layerName: LAYER_NAME,
        feature: {
            id: `tarefa-${item.linhaDoc}`,
            type: "Feature",
            geometry: {
                type: "Point",
                coordinates: [lon, lat]
            },
            properties: {}
        }
    });
}
```

## Botão para limpar reserva local

Adicione este botão abaixo do botão **PRÓXIMO**:

```html
<button id="wme-tarefas-btn-limpar-cache" style="width: 100%; margin-top: 8px; background: #607D8B; color: white; border: none; padding: 8px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold;">
    🧹 Limpar reserva local
</button>
```

E adicione em `configurarEventosPainel()`:

```js
$ui('#wme-tarefas-btn-limpar-cache').on('click', function() {
    if (!confirm("Limpar o lote local de escolas reservadas?")) return;

    limparCacheEscolas();
    currentBatch = [];
    currentIndex = 0;
    atualizarNavegacao();
    $ui('#wme-tarefas-status').text("Reserva local limpa.");
});
```

## Endpoint de liberação

```http
POST /api/escolas/uf/{UF}/liberar
Content-Type: application/json
X-API-TOKEN: SEU_TOKEN_DE_API
```

```json
{
  "username": "andresoaresdiniz"
}
```

Resposta:

```json
{
  "username": "andresoaresdiniz",
  "uf": "MG",
  "liberadas": 12,
  "message": "12 escola(s) liberada(s) com sucesso."
}
```

## Segurança

Não coloque o token da API diretamente em um userscript distribuído publicamente. Para uso pessoal, tudo bem; para distribuir a outros usuários, o ideal é:

- Cada usuário informar o próprio token em um campo do painel.
- Salvar apenas no `localStorage` do navegador.
- Ou criar um backend intermediário que valide o usuário antes de chamar a API.