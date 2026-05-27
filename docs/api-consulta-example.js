/**
 * Cliente JavaScript (fetch / Ajax) para a API de consulta de radares do ToolboxWaze.
 *
 * Pode ser usado:
 *   - No browser (import como módulo ES ou incluir via <script type="module">)
 *   - No Node.js 18+ (fetch nativo)
 *   - Adaptável para jQuery $.ajax se necessário
 *
 * Configuração: defina as constantes abaixo ou exporte-as de um config.js separado.
 */

const API_URL   = 'https://wazetoolbox.exemplo.com.br';  // sem barra final
const API_TOKEN = 'SEU_TOKEN_AQUI';

const HEADERS = {
  'Authorization': `Bearer ${API_TOKEN}`,
  'Content-Type':  'application/json',
  'Accept':        'application/json',
};


// =============================================================================
// Consulta individual
// =============================================================================

/**
 * Busca um radar pelo número de série.
 * @param {string} numeroSerie
 * @returns {Promise<object|null>} Dados do radar ou null se não encontrado.
 */
async function consultarPorSerie(numeroSerie) {
  const url = new URL(`${API_URL}/api/radares/consultar`);
  url.searchParams.set('numero_serie', numeroSerie);

  const resp = await fetch(url.toString(), { method: 'GET', headers: HEADERS });

  if (resp.status === 404) return null;
  if (!resp.ok) throw new Error(`HTTP ${resp.status}: ${await resp.text()}`);

  return resp.json();
}


/**
 * Busca um radar pelo número INMETRO da faixa.
 * @param {string} numeroInmetro
 * @returns {Promise<object|null>} Dados do radar ou null se não encontrado.
 */
async function consultarPorInmetro(numeroInmetro) {
  const url = new URL(`${API_URL}/api/radares/consultar`);
  url.searchParams.set('numero_inmetro', numeroInmetro);

  const resp = await fetch(url.toString(), { method: 'GET', headers: HEADERS });

  if (resp.status === 404) return null;
  if (!resp.ok) throw new Error(`HTTP ${resp.status}: ${await resp.text()}`);

  return resp.json();
}


// =============================================================================
// Consulta em lote
// =============================================================================

/**
 * Busca múltiplos radares por números de série (máx. 100 por chamada).
 * @param {string[]} numeros
 * @returns {Promise<{total: number, resultados: object[]}>}
 */
async function consultarLoteSerie(numeros) {
  return _consultarLote({ numeros_serie: numeros });
}


/**
 * Busca múltiplos radares por números INMETRO (máx. 100 por chamada).
 * @param {string[]} numeros
 * @returns {Promise<{total: number, resultados: object[]}>}
 */
async function consultarLoteInmetro(numeros) {
  return _consultarLote({ numeros_inmetro: numeros });
}


async function _consultarLote(payload) {
  const resp = await fetch(`${API_URL}/api/radares/consultar/lote`, {
    method:  'POST',
    headers: HEADERS,
    body:    JSON.stringify(payload),
  });

  if (!resp.ok) throw new Error(`HTTP ${resp.status}: ${await resp.text()}`);
  return resp.json();
}


// =============================================================================
// Exemplos de uso
// =============================================================================

async function exemplos() {

  // --- Consulta individual por número de série ---
  const radar = await consultarPorSerie('ABC123456');
  if (radar) {
    console.log('Radar encontrado:', radar.municipio, radar.sigla_uf);
    console.log('Situação:', radar.situacao);
    console.log('Validade:', radar.data_validade);
    console.log('Faixas:', radar.faixas);
  } else {
    console.warn('Radar não encontrado.');
  }

  // --- Consulta individual por número INMETRO ---
  const radarInmetro = await consultarPorInmetro('001/2025');
  if (radarInmetro) {
    console.log('Radar via INMETRO:', radarInmetro.tipo_medidor, radarInmetro.numero_serie);
  }

  // --- Consulta em lote por séries ---
  const { total, resultados } = await consultarLoteSerie(['ABC123', 'DEF456', 'GHI789']);
  console.log(`Encontrados ${total} de 3 consultados.`);
  resultados.forEach(r => {
    console.log(`  ${r.numero_serie} → ${r.municipio}/${r.sigla_uf} | ${r.situacao}`);
  });

  // --- Lote grande com chunking manual ---
  const todosOsNumeros = ['NS001', 'NS002' /* ... até N números */];
  const CHUNK = 100;
  const todosResultados = [];

  for (let i = 0; i < todosOsNumeros.length; i += CHUNK) {
    const chunk = todosOsNumeros.slice(i, i + CHUNK);
    const { resultados: parcial } = await consultarLoteSerie(chunk);
    todosResultados.push(...parcial);
    console.log(`Chunk ${Math.floor(i/CHUNK)+1}: ${parcial.length} encontrados.`);
  }

  console.log('Total geral:', todosResultados.length);
}

// Descomente para executar os exemplos:
// exemplos().catch(console.error);


// =============================================================================
// Adaptador jQuery (alternativa ao fetch)
// =============================================================================

/**
 * Versão jQuery $.ajax — use se fetch não estiver disponível.
 * Requer jQuery carregado na página.
 *
 * @param {string} numeroSerie
 * @returns {Promise<object|null>}
 */
function consultarPorSerieJQuery(numeroSerie) {
  return new Promise((resolve, reject) => {
    $.ajax({
      url:     `${API_URL}/api/radares/consultar`,
      method:  'GET',
      headers:  HEADERS,
      data:    { numero_serie: numeroSerie },
      success: (data) => resolve(data),
      error:   (xhr) => {
        if (xhr.status === 404) return resolve(null);
        reject(new Error(`HTTP ${xhr.status}: ${xhr.responseText}`));
      },
    });
  });
}


export {
  consultarPorSerie,
  consultarPorInmetro,
  consultarLoteSerie,
  consultarLoteInmetro,
  consultarPorSerieJQuery,
};
