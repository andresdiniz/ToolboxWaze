# API de Escolas

API protegida por token individual do usuário.

Todas as requisições devem enviar o header:

```http
X-API-TOKEN: SEU_TOKEN_DE_API
```

## Reservar 100 escolas

Reserva até 100 escolas com status `NULL` para um username.

```http
POST /api/escolas/uf/{UF}/reservar
Content-Type: application/json
X-API-TOKEN: SEU_TOKEN_DE_API
```

```json
{
  "username": "joao.silva"
}
```

Exemplo:

```http
POST /api/escolas/uf/MG/reservar
```

A resposta retorna as escolas reservadas:

```json
{
  "username": "joao.silva",
  "uf": "MG",
  "total": 100,
  "escolas": [
    {
      "id": 1,
      "escola": "Escola Exemplo",
      "status": "reservado",
      "reservado_para": "joao.silva",
      "link_waze": null,
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

```json
{
  "status": "confirmado",
  "username": "joao.silva"
}
```

Status aceitos:

- `confirmado`
- `nao_localizado`
- `pulado`

A escola só pode ser atualizada pelo mesmo username que a reservou.

## Atualizar links

```http
PATCH /api/escolas/{id}
Content-Type: application/json
X-API-TOKEN: SEU_TOKEN_DE_API
```

```json
{
  "link_waze": "https://waze.com/pt-BR/editor?env=row&permanentHazards=1134152",
  "link_area_escolar": "https://waze.com/pt-BR/editor?env=venue&venue=..."
}
```

## Exemplo JavaScript

```js
async function reservarEscolas(uf, username) {
  const response = await fetch(`${API_BASE}/api/escolas/uf/${uf}/reservar`, {
    method: 'POST',
    headers: {
      'X-API-TOKEN': API_TOKEN,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ username }),
  });

  return response.json();
}

async function atualizarStatus(escolaId, status, username) {
  const response = await fetch(`${API_BASE}/api/escolas/${escolaId}/status`, {
    method: 'PATCH',
    headers: {
      'X-API-TOKEN': API_TOKEN,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ status, username }),
  });

  return response.json();
}
```

## Migration

Após atualizar o projeto, execute:

```bash
php bin/console doctrine:migrations:migrate
```

Ou aplique o SQL em `docs/sql/reserva_status_escolas.sql`.
