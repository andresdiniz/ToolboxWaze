# Guia completo da API de Escolas

Esta API permite listar escolas por UF, reservar até 100 escolas para um username e atualizar o status de tratamento de cada escola.

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

## Endpoints

| Método | Endpoint | Descrição |
|---|---|---|
| `GET` | `/api/escolas/uf/{UF}` | Lista todas as escolas de uma UF |
| `GET` | `/api/escolas/uf/{UF}/proxima` | Retorna uma escola pendente |
| `POST` | `/api/escolas/uf/{UF}/reservar` | Reserva até 100 escolas para um username |
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

1. Reserve até 100 escolas:

```http
POST /api/escolas/uf/MG/reservar
```

2. O front-end recebe a lista de escolas reservadas.

3. Para cada escola, o usuário processa os links.

4. O front-end envia o status final:

```http
PATCH /api/escolas/{id}/status
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
  "username": "andresoaresdiniz"
}
```

Resposta:

```json
{
  "username": "andresoaresdiniz",
  "uf": "MG",
  "total": 100,
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

async function reservarEscolas(uf, username) {
  const response = await fetch(`${API_BASE}/api/escolas/uf/${uf}/reservar`, {
    method: 'POST',
    headers,
    body: JSON.stringify({ username }),
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

async function atualizarLinks(escolaId, linkWaze, linkAreaEscolar) {
  const response = await fetch(`${API_BASE}/api/escolas/${escolaId}`, {
    method: 'PATCH',
    headers,
    body: JSON.stringify({
      link_waze: linkWaze,
      link_area_escolar: linkAreaEscolar,
    }),
  });

  return response.json();
}

// Exemplo completo
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


def reservar_escolas(uf, username):
    response = requests.post(
        f"{API_BASE}/api/escolas/uf/{uf}/reservar",
        headers=headers,
        json={"username": username},
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


def atualizar_links(escola_id, link_waze=None, link_area_escolar=None):
    response = requests.patch(
        f"{API_BASE}/api/escolas/{escola_id}",
        headers=headers,
        json={
            "link_waze": link_waze,
            "link_area_escolar": link_area_escolar,
        },
        timeout=30,
    )
    response.raise_for_status()
    return response.json()


reserva = reservar_escolas("MG", "andresoaresdiniz")

for escola in reserva["escolas"]:
    atualizar_status(escola["id"], "confirmado", "andresoaresdiniz")
```

## PHP

```php
<?php

$apiBase = 'https://wazetoolbox.acheireviews.com.br';
$apiToken = 'SEU_TOKEN_DE_API';

function apiRequest(string $method, string $url, ?array $body = null): array
{
    global $apiBase, $apiToken;

    $ch = curl_init($apiBase . $url);

    $headers = [
        'X-API-TOKEN: ' . $apiToken,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true) ?? [];

    if ($status >= 400) {
        throw new RuntimeException($data['error'] ?? "Erro HTTP $status");
    }

    return $data;
}

$reserva = apiRequest('POST', '/api/escolas/uf/MG/reservar', [
    'username' => 'andresoaresdiniz',
]);

foreach ($reserva['escolas'] as $escola) {
    apiRequest('PATCH', "/api/escolas/{$escola['id']}/status", [
        'status' => 'confirmado',
        'username' => 'andresoaresdiniz',
    ]);
}
```

## cURL

### Listar escolas

```bash
curl -s \
  -H "X-API-TOKEN: SEU_TOKEN_DE_API" \
  -H "Accept: application/json" \
  "https://wazetoolbox.acheireviews.com.br/api/escolas/uf/MG"
```

### Reservar 100 escolas

```bash
curl -s -X POST \
  -H "X-API-TOKEN: SEU_TOKEN_DE_API" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"username":"andresoaresdiniz"}' \
  "https://wazetoolbox.acheireviews.com.br/api/escolas/uf/MG/reservar"
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

## PowerShell

### Reservar 100 escolas

```powershell
curl.exe -s `
  -X POST `
  -H "X-API-TOKEN: SEU_TOKEN_DE_API" `
  -H "Content-Type: application/json" `
  -H "Accept: application/json" `
  -d '{"username":"andresoaresdiniz"}' `
  "https://wazetoolbox.acheireviews.com.br/api/escolas/uf/MG/reservar" `
  -o reservadas_mg.json
```

### Confirmar escola

```powershell
curl.exe -s `
  -X PATCH `
  -H "X-API-TOKEN: SEU_TOKEN_DE_API" `
  -H "Content-Type: application/json" `
  -H "Accept: application/json" `
  -d '{"status":"confirmado","username":"andresoaresdiniz"}' `
  "https://wazetoolbox.acheireviews.com.br/api/escolas/1/status"
```

## Java

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;

public class EscolasApi {
    private static final String API_BASE = "https://wazetoolbox.acheireviews.com.br";
    private static final String API_TOKEN = "SEU_TOKEN_DE_API";

    private static final HttpClient HTTP = HttpClient.newHttpClient();

    public static HttpResponse<String> post(String path, String json) throws Exception {
        HttpRequest request = HttpRequest.newBuilder()
            .uri(URI.create(API_BASE + path))
            .header("X-API-TOKEN", API_TOKEN)
            .header("Content-Type", "application/json")
            .header("Accept", "application/json")
            .POST(HttpRequest.BodyPublishers.ofString(json))
            .build();

        return HTTP.send(request, HttpResponse.BodyHandlers.ofString());
    }

    public static HttpResponse<String> patch(String path, String json) throws Exception {
        HttpRequest request = HttpRequest.newBuilder()
            .uri(URI.create(API_BASE + path))
            .header("X-API-TOKEN", API_TOKEN)
            .header("Content-Type", "application/json")
            .header("Accept", "application/json")
            .method("PATCH", HttpRequest.BodyPublishers.ofString(json))
            .build();

        return HTTP.send(request, HttpResponse.BodyHandlers.ofString());
    }

    public static void main(String[] args) throws Exception {
        HttpResponse<String> reserva = post(
            "/api/escolas/uf/MG/reservar",
            "{\"username\":\"andresoaresdiniz\"}"
        );

        System.out.println(reserva.body());

        HttpResponse<String> status = patch(
            "/api/escolas/1/status",
            "{\"status\":\"confirmado\",\"username\":\"andresoaresdiniz\"}"
        );

        System.out.println(status.body());
    }
}
```

## C#

```csharp
using System.Net.Http.Json;

public class EscolasApi
{
    private const string ApiBase = "https://wazetoolbox.acheireviews.com.br";
    private const string ApiToken = "SEU_TOKEN_DE_API";

    private static readonly HttpClient Http = new();

    private static HttpRequestMessage CreateRequest(HttpMethod method, string path, object? body = null)
    {
        var request = new HttpRequestMessage(method, ApiBase + path);
        request.Headers.Add("X-API-TOKEN", ApiToken);
        request.Headers.Accept.ParseAdd("application/json");

        if (body is not null)
        {
            request.Content = JsonContent.Create(body);
        }

        return request;
    }

    public static async Task<string> ReservarEscolas(string uf, string username)
    {
        var response = await Http.SendAsync(CreateRequest(
            HttpMethod.Post,
            $"/api/escolas/uf/{uf}/reservar",
            new { username }
        ));

        response.EnsureSuccessStatusCode();
        return await response.Content.ReadAsStringAsync();
    }

    public static async Task<string> AtualizarStatus(int escolaId, string status, string username)
    {
        var response = await Http.SendAsync(CreateRequest(
            HttpMethod.Patch,
            $"/api/escolas/{escolaId}/status",
            new { status, username }
        ));

        response.EnsureSuccessStatusCode();
        return await response.Content.ReadAsStringAsync();
    }
}
```

## Boas práticas

- Nunca exponha o `X-API-TOKEN` em repositórios públicos ou no front-end acessível por terceiros.
- Use HTTPS sempre.
- Trate respostas HTTP 401, 403, 404 e 422.
- Reserve apenas quando o usuário for iniciar o trabalho.
- Envie `confirmado`, `nao_localizado` ou `pulado` após concluir cada escola.
- Não reenvie status para uma escola reservada por outro username.
