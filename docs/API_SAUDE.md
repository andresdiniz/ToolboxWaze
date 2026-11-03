# API de Estabelecimentos de Saúde (CNES)

Mesmo padrão da API de escolas: token individual no header `X-API-TOKEN`, acesso por UF e reserva por usuário.
Os endpoints de **leitura** exigem acesso à UF; reservar/renovar/liberar/atualizar exigem a permissão **Saúde** (`PERM_SAUDE`).

Campos retornados (somente o necessário):

```json
{
  "id": 1,
  "cnes": "0000140",
  "nome": "USF JUSSARAL",
  "ativo": true,
  "uf": "PE",
  "municipio": "CABO DE SANTO AGOSTINHO",
  "logradouro": "ESTRADA DA VITORIA - S/N",
  "telefone": "(81)33023022",
  "latitude": "-8.21736134",
  "longitude": "-35.22963424",
  "link_waze": null,
  "status": null,
  "reservado_para": null,
  "reservado_em": null,
  "updated_at": null
}
```

## Listar por UF

```http
GET /api/saude/uf/PE?municipio=CABO DE SANTO AGOSTINHO&ativo=1
```

`municipio` e `ativo` (`1`/`0`) são filtros opcionais. Resposta: `{ "uf", "total", "unidades": [...] }`.

## Próximo pendente

```http
GET /api/saude/uf/PE/proxima            # próximo ativo sem status
GET /api/saude/uf/PE/proxima?atualizada=1   # último com status definido
```

## Reservar / renovar / liberar (TTL de 30 min)

```http
POST /api/saude/uf/PE/reservar   {"username": "andresoaresdiniz", "limite": 50}
POST /api/saude/uf/PE/renovar    {"username": "andresoaresdiniz"}
POST /api/saude/uf/PE/liberar    {"username": "andresoaresdiniz"}
```

A reserva considera apenas estabelecimentos **ativos**.

## Atualizar status

```http
PATCH /api/saude/{id}/status   {"status": "confirmado", "username": "andresoaresdiniz"}
```

Valores: `confirmado`, `nao_localizado`, `pulado`.

## Atualizar link do Waze

```http
PATCH /api/saude/{id}   {"link_waze": "https://waze.com/pt-BR/editor?env=row&venue=..."}
```

---

## Instalação

1. Copie os arquivos para o projeto (mesmos caminhos) e rode `php bin/console doctrine:migrations:migrate`.
2. Em **Admin → Usuários**, marque a permissão **Saúde** para quem vai usar (admins já têm).
3. Importe o CSV do CNES:

```bash
php bin/console app:import-saude --file=/caminho/cnes.csv
# ou defina SAUDE_CNES_CSV_URL no .env e rode apenas:
php bin/console app:import-saude
```

Colunas lidas do CSV (cabeçalho sem distinção de acento/maiúscula; separador `,` `;` ou tab detectado sozinho):
`CNES`, `NOME FANTASIA` (cai para `RAZÃO SOCIAL` se vazio), `STATUS DO ESTABELECIMENTO` (`ATIVO` → `ativo=true`), `UF`, `MUNICÍPIO`,
`LOGRADOURO`, `TELEFONE` e `location` (`"-8.21736134 -35.22963424"`) — ou `LATITUDE`/`LONGITUDE` separadas.
Demais colunas são ignoradas. "Não informado" vira nulo. Reimportar é seguro: só linhas alteradas são atualizadas e os campos de reserva nunca são tocados.

4. Cron para liberar reservas esquecidas (igual ao de escolas):

```cron
*/5 * * * * cd /var/www/app && php bin/console app:saude:liberar-expiradas --ttl=30 --env=prod --no-debug >/dev/null 2>&1
```
