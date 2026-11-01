# API de Escolas

API protegida por token individual do usuário.

Todas as requisições devem enviar o header:

```http
X-API-TOKEN: SEU_TOKEN_DE_API
```

## Validar token

```http
GET /api/auth/validate
X-API-TOKEN: SEU_TOKEN_DE_API
Accept: application/json
```

Resposta quando o token é válido:

```json
{
  "valid": true,
  "username": "andresoaresdiniz",
  "nome": "André Soares Diniz"
}
```

Resposta quando o token é inválido:

```json
{
  "valid": false,
  "error": "Token inválido."
}
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
