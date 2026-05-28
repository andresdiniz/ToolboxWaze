# Guia de Autenticação — API WazeToolbox

Todos os endpoints da API requerem um **token pessoal** enviado no header
`Authorization`. Este guia explica como obter, usar e revogar esse token.

---

## 1. Como obter seu token

### Passo a passo (interface web)

1. Faça login no site: `https://<seu-dominio>`
2. Acesse o menu do seu perfil e clique em **"Token de API"**,
   ou navegue diretamente para:
   ```
   https://<seu-dominio>/perfil/api-token
   ```
3. Se ainda não tiver um token, clique em **"Gerar meu token"**.
4. O token será exibido na tela (64 caracteres hexadecimais).
5. Clique no ícone de **copiar** ao lado do campo.

> ⚠️ **Guarde o token em local seguro.** Ele tem o mesmo nível de acesso
> que sua senha para as rotas de API. Não compartilhe publicamente.

---

## 2. Como usar o token

Envie o token no header `Authorization` em **todas** as requisições:

```
Authorization: Bearer <seu-token-de-64-chars>
```

### Python

```python
import os
import requests

API_TOKEN = os.getenv('WAZE_API_TOKEN')  # recomendado via variável de ambiente

headers = {
    'Authorization': f'Bearer {API_TOKEN}',
    'Content-Type':  'application/json',
}

# Exemplo: consulta individual
resp = requests.get(
    'https://<seu-dominio>/api/radares/consultar',
    headers=headers,
    params={'numero_serie': 'ABC123456'},
)
print(resp.json())
```

### JavaScript (fetch)

```javascript
const token = 'seu_token_aqui';

const resp = await fetch('/api/radares/consultar?numero_serie=ABC123456', {
    headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
    },
});
const radar = await resp.json();
```

### cURL

```bash
curl -H "Authorization: Bearer SEU_TOKEN" \
     "https://<seu-dominio>/api/radares/consultar?numero_serie=ABC123456"
```

---

## 3. Endpoints disponíveis

| Método | Endpoint | Descrição | Auth |
|--------|----------|-----------|------|
| `POST` | `/api/radares/importar` | Importação bulk (até 500) | ✅ Bearer |
| `GET`  | `/api/radares/consultar` | Busca por N/S ou INMETRO | ✅ Bearer |
| `POST` | `/api/radares/consultar/lote` | Busca em lote (até 100) | ✅ Bearer |
| `GET`  | `/api/meu-token` | Retorna token atual (JSON, requer sessão web) | ✅ Sessão |
| `POST` | `/api/token/gerar` | Gera novo token (JSON, requer sessão web) | ✅ Sessão |
| `POST` | `/api/token/revogar` | Revoga token (JSON, requer sessão web) | ✅ Sessão |

---

## 4. Parâmetros de consulta

### GET `/api/radares/consultar`

| Parâmetro | Tipo | Obrigatório | Exemplo |
|-----------|------|-------------|--------|
| `numero_serie` | string | Um dos dois | `ABC123456` |
| `numero_inmetro` | string | Um dos dois | `001/2025` |

### POST `/api/radares/consultar/lote`

Body JSON:

```json
{
  "numeros_serie":   ["ABC123", "DEF456"],
  "numeros_inmetro": ["001/2025"]
}
```

Limite: **100 itens** por requisição (soma de série + INMETRO).

---

## 5. Respostas e códigos HTTP

| Código | Situação |
|--------|----------|
| `200` | Sucesso |
| `201` | Token criado com sucesso |
| `207` | Importação parcial (alguns erros) |
| `400` | Parâmetros inválidos |
| `401` | Token ausente, inválido ou revogado |
| `404` | Radar não encontrado |
| `422` | Limite de batch excedido |

Exemplo de resposta `401`:
```json
{ "error": "Unauthorized" }
```

Ao receber `401`, acesse `/perfil/api-token` e gere um novo token.

---

## 6. Gerenciar o token

### Regenerar (substitui o atual)

Acesse `/perfil/api-token` → clique em **"Regenerar token"**.
O token anterior é **invalidado imediatamente**.

### Revogar (apaga sem gerar novo)

Acesse `/perfil/api-token` → clique em **"Revogar token"**.
Após revogar, todas as requisições com o token antigo retornam `401`.

### Via JSON API (sessão ativa)

```bash
# Gerar novo token
curl -X POST https://<seu-dominio>/api/token/gerar \
     -b 'PHPSESSID=sua_sessao'  # necessita cookie de sessão

# Revogar
curl -X POST https://<seu-dominio>/api/token/revogar \
     -b 'PHPSESSID=sua_sessao'
```

---

## 7. Variáveis de ambiente recomendadas

```bash
# Linux / macOS
export WAZE_BASE_URL='https://<seu-dominio>'
export WAZE_API_TOKEN='seu_token_de_64_chars'

# Windows (PowerShell)
$env:WAZE_API_TOKEN = 'seu_token_de_64_chars'
```

Nunca coloque o token diretamente no código-fonte versionado.

---

## 8. Características de segurança do token

| Característica | Detalhe |
|---|---|
| Tamanho | 64 caracteres hexadecimais (256 bits de entropia) |
| Geração | `random_bytes(32)` — criptograficamente seguro |
| Armazenamento | Banco de dados, coluna com índice `UNIQUE` |
| Lookup | O(1) — busca direta por índice, sem varrer a tabela |
| Expiração | Não expira automaticamente |
| Revogação | Imediata (seta NULL no banco) |
| Por usuário | Cada usuário tem exatamente 1 token ativo por vez |
