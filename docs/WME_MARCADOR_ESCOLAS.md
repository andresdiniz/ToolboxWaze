# Integração: Escolas no WME Marcador de Tarefas

O WME Marcador de Tarefas integra o módulo **Escolas** da API do ToolboxWaze.

Cada usuário usa o próprio token da API, salvo apenas no `localStorage` do navegador.

## Configuração

O userscript usa esta configuração:

```js
const ESCOLAS_API = {
    BASE: "https://wazetoolbox.acheireviews.com.br",
    TOKEN_STORAGE_KEY: "wme_marcador_escolas_token",
    LOTE: 50,
    CACHE_KEY: "wme_marcador_escolas_reservadas"
};
```

O token **não fica escrito no userscript**.

Ele é informado pelo usuário no modal e salvo apenas no navegador:

```js
localStorage.setItem("wme_marcador_escolas_token", token);
```

## Como o usuário configura o token

1. Abra o painel **Inspetor de Tarefas**.
2. Clique em **🔑 Token da API**.
3. Clique em **Abrir página do token**.
4. Faça login no ToolboxWaze.
5. Clique em **Gerar novo token**.
6. Copie o token.
7. Cole no campo do modal.
8. Clique em **Validar e salvar**.

O userscript valida o token antes de salvá-lo.

## Validação do token

Ao clicar em **Validar e salvar**, o userscript chama:

```http
GET /api/auth/validate
X-API-TOKEN: SEU_TOKEN_DE_API
Accept: application/json
```

Resposta para token válido:

```json
{
  "valid": true,
  "username": "andresoaresdiniz",
  "nome": "André Soares Diniz"
}
```

Resposta para token inválido:

```json
{
  "valid": false,
  "error": "Token inválido."
}
```

O token só é salvo no `localStorage` quando a API responde:

```json
{
  "valid": true
}
```

Se o token for inválido, o modal exibe o erro e o token não é salvo.

## Função de validação

```js
function validarTokenEscolas(token) {
    return new Promise((resolve, reject) => {
        GM_xmlhttpRequest({
            method: "GET",
            url: ESCOLAS_API.BASE + "/api/auth/validate",
            headers: {
                "X-API-TOKEN": token,
                "Accept": "application/json"
            },
            timeout: 30000,
            onload: function(res) {
                try {
                    const dados = JSON.parse(res.responseText);

                    if (res.status === 200 && dados.valid === true) {
                        resolve(dados);
                    } else {
                        reject(new Error(dados.error || "Token inválido."));
                    }
                } catch (e) {
                    reject(new Error("Resposta inválida ao validar o token."));
                }
            },
            onerror: function() {
                reject(new Error("Falha de conexão ao validar o token."));
            },
            ontimeout: function() {
                reject(new Error("Tempo limite ao validar o token."));
            }
        });
    });
}
```

## Uso do token nas chamadas

Todas as chamadas da API de Escolas enviam o token salvo:

```js
headers: {
    "X-API-TOKEN": obterTokenEscolas(),
    "Content-Type": "application/json",
    "Accept": "application/json"
}
```

Se não houver token salvo, o modal de configuração abre automaticamente.

## Reserva em lote

Use **reserva em lote de 50 escolas**, e não uma por vez.

| Estratégia | Vantagem | Desvantagem |
|---|---|---|
| Reservar 1 por vez | Menor risco de reserva parada | Muitas requisições e mais lentidão |
| Reservar 50 e salvar no `localStorage` | Rápido, menos requisições e funciona bem offline | Escolas podem ficar reservadas se o usuário não concluir |
| Reservar 100 | Menos chamadas ainda | Lote maior parado em caso de abandono |

**Recomendação:** reservar **50**, salvar no `localStorage` e enviar apenas a ação final de cada escola para a API.

## Inatividade de 30 minutos

O userscript acompanha a última interação do usuário. Após **30 minutos sem uso**, ele:

1. Chama `POST /api/escolas/uf/{UF}/liberar`.
2. Libera apenas as escolas com `status = reservado` e `reservado_para = username`.
3. Limpa o `localStorage`.
4. Limpa a lista local e os marcadores do mapa.

```js
const INATIVIDADE_LIMITE_MS = 30 * 60 * 1000;
const INATIVIDADE_CHECK_MS = 60 * 1000;
```

## Endpoints usados

| Método | Endpoint | Uso |
|---|---|---|
| `GET` | `/api/auth/validate` | Validar o token antes de salvar |
| `POST` | `/api/escolas/uf/{UF}/reservar` | Reservar até 50 escolas |
| `POST` | `/api/escolas/uf/{UF}/liberar` | Liberar reserva por inatividade |
| `PATCH` | `/api/escolas/{id}/status` | Confirmar, não localizar ou pular escola |

## Segurança

- Cada usuário usa o próprio token.
- O token é salvo apenas no `localStorage` do navegador.
- O token não é incluído no código-fonte do userscript.
- O token é validado antes de ser salvo.
- O usuário pode remover o token salvo pelo botão **Remover**.
- Ao receber `401`, o usuário deve gerar um novo token no site.
