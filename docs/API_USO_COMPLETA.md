# Guia completo da API de Escolas

Esta API permite listar escolas por UF, reservar escolas para um username, liberar reservas e atualizar o status de tratamento de cada escola.

Base URL:

```text
https://wazetoolbox.acheireviews.com.br
```

## Autenticação

Todas as requisições exigem o header:

```http
X-API-TOKEN: SEU_TOKEN_DE_API
```

O token é individual e pode ser gerado em:

```text
https://wazetoolbox.acheireviews.com.br/profile/api-token
```

Antes de salvar o token em um aplicativo ou userscript, valide-o:

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

## Endpoints

| Método | Endpoint | Descrição |
|---|---|---|
| `GET` | `/api/auth/validate` | Valida o token da API |
| `GET` | `/api/escolas/uf/{UF}` | Lista todas as escolas de uma UF |
| `GET` | `/api/escolas/uf/{UF}/proxima` | Retorna uma escola pendente |
| `POST` | `/api/escolas/uf/{UF}/reservar` | Reserva até 50 escolas para um username |
| `POST` | `/api/escolas/uf/{UF}/liberar` | Libera escolas reservadas por um username |
| `PATCH` | `/api/escolas/{id}/status` | Atualiza o status de uma escola |
| `POST` | `/api/escolas/{id}/status` | Alternativa ao PATCH |
| `PATCH` | `/api/escolas/{id}` | Atualiza links do Waze |
| `POST` | `/api/escolas/{id}` | Alternativa ao PATCH de links |

## Status

| Status | Significado |
|---|---|
| `NULL` | Escola ainda não reservada |
| `reservado` | Escola reservada para um username |
| `confirmado` | Escola confirmada após tratamento |
| `nao_localizado` | Escola não localizada |
| `pulado` | Escola pulada pelo usuário |

## Fluxo recomendado

1. Valide o token:

```http
GET /api/auth/validate
```

2. Reserve até 50 escolas:

```http
POST /api/escolas/uf/MG/reservar
```

3. O front-end recebe a lista de escolas reservadas.

4. Para cada escola, o usuário processa os links.

5. O front-end envia o status final:

```http
PATCH /api/escolas/{id}/status
```

6. Após 30 minutos sem uso, o WME Marcador libera automaticamente a reserva:

```http
POST /api/escolas/uf/MG/liberar
```

## Reservar escolas

```http
POST /api/escolas/uf/{UF}/reservar
Content-Type: application/json
X-API-TOKEN: SEU_TOKEN_DE_API
```

Corpo:

```json
{
  "username": "andresoaresdiniz",
  "limite": 50
}
```

Resposta:

```json
{
  "username": "andresoaresdiniz",
  "uf": "MG",
  "total": 50,
  "escolas": [
    {
      "id": 1,
      "codigo_inep": "12345678",
      "escola": "Escola Exemplo",
      "uf": "MG",
      "municipio": "Belo Horizonte",
      "status": "reservado",
      "reservado_para": "andresoaresdiniz",
      "link_waze": null,
      "permanent_hazard_id": null,
      "link_area_escolar": null
    }
  ]
}
```

## Liberar reservas

```http
POST /api/escolas/uf/{UF}/liberar
Content-Type: application/json
X-API-TOKEN: SEU_TOKEN_DE_API
```

Corpo:

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

## Atualizar status

```http
PATCH /api/escolas/{id}/status
Content-Type: application/json
X-API-TOKEN: SEU_TOKEN_DE_API
```

Corpo:

```json
{
  "status": "confirmado",
  "username": "andresoaresdiniz"
}
```

Valores válidos para `status`:

```text
confirmado
nao_localizado
pulado
```

Resposta:

```json
{
  "message": "Status atualizado com sucesso.",
  "escola": {
    "id": 1,
    "status": "confirmado",
    "reservado_para": "andresoaresdiniz"
  }
}
```

## Atualizar links

```http
PATCH /api/escolas/{id}
Content-Type: application/json
X-API-TOKEN: SEU_TOKEN_DE_API
```

Corpo:

```json
{
  "link_waze": "https://waze.com/pt-BR/editor?env=row&permanentHazards=1134152",
  "link_area_escolar": "https://waze.com/pt-BR/editor?env=venue&venue=..."
}
```

Para limpar um link:

```json
{
  "link_waze": null
}
```

## Erros HTTP

| Código | Causa |
|---|---|
| 400 | UF inválida, JSON inválido, username ausente ou status inválido |
| 401 | Token ausente ou inválido |
| 403 | Sem permissão, sem acesso à UF ou escola reservada para outro username |
| 404 | Escola ou rota não encontrada |
| 422 | Link do Waze inválido |

# Exemplos por linguagem

## JavaScript

```js
const API_BASE = 'https://wazetoolbox.acheireviews.com.br';
const API_TOKEN = 'SEU_TOKEN_DE_API';

const headers = {
  'X-API-TOKEN': API_TOKEN,
  'Content-Type': 'application/json',
  'Accept': 'application/json',
};

async function validarToken() {
  const response = await fetch(`${API_BASE}/api/auth/validate`, {
    headers: {
      'X-API-TOKEN': API_TOKEN,
      'Accept': 'application/json',
    },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.error ?? 'Token inválido.');
  }

  return response.json();
}

async function reservarEscolas(uf, username) {
  const response = await fetch(`${API_BASE}/api/escolas/uf/${uf}/reservar`, {
    method: 'POST',
    headers,
    body: JSON.stringify({ username, limite: 50 }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.error ?? `Erro HTTP ${response.status}`);
  }

  return response.json();
}

async function atualizarStatus(escolaId, status, username) {
  const response = await fetch(`${API_BASE}/api/escolas/${escolaId}/status`, {
    method: 'PATCH',
    headers,
    body: JSON.stringify({ status, username }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.error ?? `Erro HTTP ${response.status}`);
  }

  return response.json();
}

const validacao = await validarToken();
console.log(validacao.username);

const reserva = await reservarEscolas('MG', 'andresoaresdiniz');

for (const escola of reserva.escolas) {
  await atualizarStatus(escola.id, 'confirmado', 'andresoaresdiniz');
}
```

## Python

```python
import requests

API_BASE = "https://wazetoolbox.acheireviews.com.br"
API_TOKEN = "SEU_TOKEN_DE_API"

headers = {
    "X-API-TOKEN": API_TOKEN,
    "Content-Type": "application/json",
    "Accept": "application/json",
}


def validar_token():
    response = requests.get(
        f"{API_BASE}/api/auth/validate",
        headers=headers,
        timeout=30,
    )
    response.raise_for_status()
    return response.json()


def reservar_escolas(uf, username):
    response = requests.post(
        f"{API_BASE}/api/escolas/uf/{uf}/reservar",
        headers=headers,
        json={"username": username, "limite": 50},
        timeout=30,
    )
    response.raise_for_status()
    return response.json()


def atualizar_status(escola_id, status, username):
    response = requests.patch(
        f"{API_BASE}/api/escolas/{escola_id}/status",
        headers=headers,
        json={"status": status, "username": username},
        timeout=30,
    )
    response.raise_for_status()
    return response.json()


validacao = validar_token()
print(validacao["username"])

reserva = reservar_escolas("MG", "andresoaresdiniz")

for escola in reserva["escolas"]:
    atualizar_status(escola["id"], "confirmado", "andresoaresdiniz")
```

## cURL

### Validar token

```bash
curl -s \
  -H "X-API-TOKEN: SEU_TOKEN_DE_API" \
  -H "Accept: application/json" \
  "https://wazetoolbox.acheireviews.com.br/api/auth/validate"
```

### Reservar 50 escolas

```bash
curl -s -X POST \
  -H "X-API-TOKEN: SEU_TOKEN_DE_API" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"username":"andresoaresdiniz","limite":50}' \
  "https://wazetoolbox.acheireviews.com.br/api/escolas/uf/MG/reservar"
```

### Liberar reservas

```bash
curl -s -X POST \
  -H "X-API-TOKEN: SEU_TOKEN_DE_API" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"username":"andresoaresdiniz"}' \
  "https://wazetoolbox.acheireviews.com.br/api/escolas/uf/MG/liberar"
```

### Confirmar escola

```bash
curl -s -X PATCH \
  -H "X-API-TOKEN: SEU_TOKEN_DE_API" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"status":"confirmado","username":"andresoaresdiniz"}' \
  "https://wazetoolbox.acheireviews.com.br/api/escolas/1/status"
```

## Boas práticas

- Nunca exponha o `X-API-TOKEN` em repositórios públicos ou no front-end acessível por terceiros.
- Use HTTPS sempre.
- Valide o token antes de salvá-lo localmente.
- Trate respostas HTTP 401, 403, 404 e 422.
- Reserve apenas quando o usuário for iniciar o trabalho.
- Envie `confirmado`, `nao_localizado` ou `pulado` após concluir cada escola.
- Não reenvie status para uma escola reservada por outro username.
- Libere reservas após 30 minutos sem uso.
