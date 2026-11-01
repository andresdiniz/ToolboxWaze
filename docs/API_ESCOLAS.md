# API de Escolas

API protegida por token individual do usuário.

Todas as requisições devem enviar o header:

```http
X-API-TOKEN: SEU_TOKEN_DE_API
```

Gere ou gerencie seu token em:

```text
/profile/api-token
```

## Listar escolas por estado

```http
GET /api/escolas/uf/{UF}
```

Exemplo:

```http
GET /api/escolas/uf/MG
```

### Resposta

```json
{
  "uf": "MG",
  "total": 2,
  "escolas": [
    {
      "id": 1,
      "codigo_inep": "12345678",
      "escola": "Escola Exemplo",
      "uf": "MG",
      "municipio": "Belo Horizonte",
      "endereco": "Rua Exemplo, 123",
      "telefone": "31999999999",
      "latitude": "-19.91670000",
      "longitude": "-43.93450000",
      "link_waze": "https://waze.com/pt-BR/editor?env=row&permanentHazards=1134152",
      "permanent_hazard_id": 1134152,
      "link_area_escolar": "https://waze.com/pt-BR/editor?env=venue&venue=...",
      "updated_at": "2026-11-01T18:00:00+00:00"
    }
  ]
}
```

A resposta inclui todos os dados cadastrados da escola, além de `link_waze`, `permanent_hazard_id` e `link_area_escolar`.

## Atualizar links

```http
PATCH /api/escolas/{id}
```

Também é aceito:

```http
POST /api/escolas/{id}
```

Envie um JSON com um ou ambos os campos:

```json
{
  "link_waze": "https://waze.com/pt-BR/editor?env=row&permanentHazards=1134152",
  "link_area_escolar": "https://waze.com/pt-BR/editor?env=venue&venue=..."
}
```

Para limpar um link, envie `null`:

```json
{
  "link_waze": null
}
```

### Exemplo completo

```http
PATCH /api/escolas/1
Content-Type: application/json
X-API-TOKEN: SEU_TOKEN_DE_API
```

```json
{
  "link_waze": "https://waze.com/pt-BR/editor?env=row&permanentHazards=1134152",
  "link_area_escolar": "https://waze.com/pt-BR/editor?env=venue&venue=..."
}
```

Ao salvar `link_waze`, o sistema extrai automaticamente o valor de `permanentHazards` e grava em `permanent_hazard_id`.

## Permissões

- O usuário precisa estar aprovado.
- Para atualizar escolas, é necessário ter a permissão `PERM_ESCOLAS`.
- Administradores têm acesso a todas as UFs.
- Usuários comuns respeitam as UFs autorizadas no painel.

## Exemplo JavaScript

```js
const API_BASE = 'https://seudominio.com';
const API_TOKEN = 'SEU_TOKEN_DE_API';

async function buscarEscolas(uf) {
  const response = await fetch(`${API_BASE}/api/escolas/uf/${uf}`, {
    headers: {
      'X-API-TOKEN': API_TOKEN,
      'Accept': 'application/json',
    },
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
    headers: {
      'X-API-TOKEN': API_TOKEN,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      link_waze: linkWaze,
      link_area_escolar: linkAreaEscolar,
    }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.error ?? `Erro HTTP ${response.status}`);
  }

  return response.json();
}
```

## Códigos HTTP

| Código | Significado |
|---|---|
| 200 | Requisição realizada com sucesso |
| 400 | UF inválida, JSON inválido ou nenhum campo enviado |
| 401 | Token ausente ou inválido |
| 403 | Usuário sem permissão ou sem acesso à UF |
| 404 | Escola não encontrada |
| 422 | Link do Waze inválido |
