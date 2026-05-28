/**
 * WazeToolbox — Consulta de radares via JavaScript / Ajax
 * =========================================================
 *
 * Autenticação
 * -----------
 * Cada usuário possui um token pessoal. Obtenha o seu em:
 *
 *     https://<seu-dominio>/perfil/api-token
 *
 * Como obter o token (passo a passo)
 * -----------------------------------
 * 1. Acesse https://<seu-dominio>/perfil/api-token
 * 2. Clique em "Gerar meu token"
 * 3. Copie o token exibido (64 caracteres hex)
 * 4. Substitua 'SEU_TOKEN_AQUI' abaixo
 *
 * Revogação
 * ---------
 * Acesse /perfil/api-token e clique em "Revogar token".
 */

const WAZE_BASE_URL  = 'https://<seu-dominio>';
const WAZE_API_TOKEN = 'SEU_TOKEN_AQUI'; // cole aqui ou leia de uma variável segura

const HEADERS = {
    'Authorization': `Bearer ${WAZE_API_TOKEN}`,
    'Content-Type':  'application/json',
    'Accept':        'application/json',
};

// ---------------------------------------------------------------------------
// Fetch nativo
// ---------------------------------------------------------------------------

/**
 * Consulta um radar por Número de Série ou Número INMETRO.
 *
 * @param {'numero_serie'|'numero_inmetro'} campo
 * @param {string} valor
 * @returns {Promise<object|null>}  null se não encontrado (404)
 */
async function consultarRadar(campo, valor) {
    const url = new URL(`${WAZE_BASE_URL}/api/radares/consultar`);
    url.searchParams.set(campo, valor);

    const resp = await fetch(url.toString(), { headers: HEADERS });

    if (resp.status === 401) {
        throw new Error('Token inválido. Acesse /perfil/api-token para gerar um novo.');
    }
    if (resp.status === 404) return null;
    if (!resp.ok) throw new Error(`Erro ${resp.status}: ${await resp.text()}`);

    return resp.json();
}

/**
 * Consulta até 100 radares em lote.
 *
 * @param {{ numeros_serie?: string[], numeros_inmetro?: string[] }} params
 * @returns {Promise<object[]>}
 */
async function consultarLote(params) {
    const resp = await fetch(`${WAZE_BASE_URL}/api/radares/consultar/lote`, {
        method:  'POST',
        headers: HEADERS,
        body:    JSON.stringify(params),
    });

    if (resp.status === 401) {
        throw new Error('Token inválido. Acesse /perfil/api-token para gerar um novo.');
    }
    if (!resp.ok) throw new Error(`Erro ${resp.status}: ${await resp.text()}`);

    const data = await resp.json();
    return data.resultados ?? [];
}

// ---------------------------------------------------------------------------
// Adapter jQuery ($.ajax)
// ---------------------------------------------------------------------------

function consultarRadarJQuery(campo, valor) {
    return $.ajax({
        url:     `${WAZE_BASE_URL}/api/radares/consultar`,
        method:  'GET',
        headers: HEADERS,
        data:    { [campo]: valor },
    });
}

function consultarLoteJQuery(params) {
    return $.ajax({
        url:         `${WAZE_BASE_URL}/api/radares/consultar/lote`,
        method:      'POST',
        headers:     HEADERS,
        contentType: 'application/json',
        data:        JSON.stringify(params),
    });
}

// ---------------------------------------------------------------------------
// Exemplos de uso
// ---------------------------------------------------------------------------

// Busca individual por Número de Série
consultarRadar('numero_serie', 'ABC123456')
    .then(radar => {
        if (radar) console.log('Radar encontrado:', radar);
        else       console.log('Radar não encontrado.');
    })
    .catch(console.error);

// Busca individual por INMETRO
consultarRadar('numero_inmetro', '001/2025')
    .then(console.log)
    .catch(console.error);

// Lote por Número de Série
consultarLote({ numeros_serie: ['ABC123', 'DEF456', 'GHI789'] })
    .then(resultados => console.log(`${resultados.length} radares encontrados`, resultados))
    .catch(console.error);
