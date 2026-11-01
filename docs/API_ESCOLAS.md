# API de Escolas

API protegida por token individual do usuário.

Todas as requisições devem enviar o header:

```http
X-API-TOKEN: SEU_TOKEN_DE_API
```

## Reservar escolas

```http
POST /api/escolas/uf/{UF}/reservar
Content-Type: application/json
X-API-TOKEN: SEU_TOKEN_DE_API
```

```json
{
  "username": "andresoaresdiniz",
  "limite": 50
}
```

- `limite` é opcional.
- Padrão: `100`.
- Máximo: `100`.

A API reserva escolas com `status IS NULL`, define `status = reservado` e `reservado_para = username`.

## Liberar reservas

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

Libera apenas escolas com:

- `status = reservado`
- `reservado_para = username`

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

```json
{
  "status": "confirmado",
  "username": "andresoaresdiniz"
}
```

Status aceitos:

- `confirmado`
- `nao_localizado`
- `pulado`

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
async function liberarPorInatividade(uf, username) {
  const response = await fetch(`${API_BASE}/api/escolas/uf/${uf}/liberar`, {
    method: 'POST',
    headers: {
      'X-API-TOKEN': API_TOKEN,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ username }),
  });

  return response.json();
}
```

## Migration

Após atualizar o projeto, execute:

```bash
php bin/console doctrine:migrations:migrate
```
