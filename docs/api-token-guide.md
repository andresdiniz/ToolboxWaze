# Guia de Autenticação — API WazeToolbox

A API do WazeToolbox usa um **token pessoal** enviado no header `X-API-TOKEN`.

Este guia explica como obter, validar, usar e revogar o token.

## 1. Como obter seu token

1. Faça login no site:

```text
https://wazetoolbox.acheireviews.com.br
```

2. Acesse a página do token:

```text
https://wazetoolbox.acheireviews.com.br/profile/api-token
```

3. Se ainda não tiver um token, clique em **Gerar novo token**.
4. Copie o token exibido na tela.

> ⚠️ **Guarde o token em local seguro.** Ele permite usar as rotas da API em seu nome.
> Não compartilhe publicamente.

## 2. Como usar o token

Envie o token no header `X-API-TOKEN` em todas as requisições protegidas:

```http
X-API-TOKEN: SEU_TOKEN_DE_API
```

### JavaScript

```js
const response = await fetch(
  "https://wazetoolbox.acheireviews.com.br/api/auth/validate",
  {
    headers: {
      "X-API-TOKEN": token,
      "Accept": "application/json"
    }
  }
);
```

### cURL

```bash
curl -H "X-API-TOKEN: SEU_TOKEN_DE_API" \
     -H "Accept: application/json" \
     "https://wazetoolbox.acheireviews.com.br/api/auth/validate"
```

## 3. Validar o token

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

Código HTTP para token inválido:

```text
401 Unauthorized
```

## 4. Uso no WME Marcador de Tarefas

No WME Marcador de Tarefas:

1. Clique em **🔑 Token da API**.
2. Clique em **Abrir página do token**.
3. Faça login no ToolboxWaze.
4. Clique em **Gerar novo token**.
5. Copie o token.
6. Cole no modal do userscript.
7. Clique em **Validar e salvar**.

O userscript chama `GET /api/auth/validate` antes de salvar.

- Se o token for válido, ele é salvo no `localStorage` do navegador.
- Se o token for inválido, ele **não é salvo** e o modal exibe o erro.

Chave usada no `localStorage`:

```js
"wme_marcador_escolas_token"
```

## 5. Endpoints disponíveis

| Método | Endpoint | Descrição | Autenticação |
|---|---|---|---|
| `GET` | `/api/auth/validate` | Valida o token da API | `X-API-TOKEN` |
| `GET` | `/api/escolas/uf/{UF}` | Lista escolas de uma UF | `X-API-TOKEN` |
| `GET` | `/api/escolas/uf/{UF}/proxima` | Retorna uma escola pendente | `X-API-TOKEN` |
| `POST` | `/api/escolas/uf/{UF}/reservar` | Reserva escolas para um username | `X-API-TOKEN` |
| `POST` | `/api/escolas/uf/{UF}/liberar` | Libera reservas de um username | `X-API-TOKEN` |
| `PATCH` | `/api/escolas/{id}/status` | Atualiza o status de uma escola | `X-API-TOKEN` |
| `PATCH` | `/api/escolas/{id}` | Atualiza links do Waze | `X-API-TOKEN` |

## 6. Gerenciar o token

### Regenerar

Acesse `/profile/api-token` e clique em **Gerar novo token**.

O token anterior deixa de ser válido.

### Remover do navegador

No painel do WME Marcador de Tarefas, clique em **Remover**.

Isso apaga apenas o token salvo no `localStorage` daquele navegador.

## 7. Segurança

- Nunca coloque o token diretamente no código-fonte versionado.
- Não compartilhe o token em grupos, prints ou repositórios públicos.
- Use HTTPS sempre.
- Cada usuário deve usar o próprio token.
- Salve o token apenas no `localStorage` do navegador do usuário.
- Ao receber `401`, solicite que o usuário gere um novo token.
