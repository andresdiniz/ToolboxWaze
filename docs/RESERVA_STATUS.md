# Reserva e status das escolas

## Colunas novas

| Coluna | Tipo | Nulo | Descrição |
|---|---|---|---|
| `status` | `VARCHAR(20)` | Sim | `NULL`, `reservado`, `confirmado`, `nao_localizado` ou `pulado` |
| `reservado_para` | `VARCHAR(100)` | Sim | Username responsável pela escola |

## Fluxo

1. O front-end envia `UF` e `username` para `POST /api/escolas/uf/{UF}/reservar`.
2. A API reserva até 100 escolas com `status IS NULL`.
3. Cada escola recebe `status = reservado` e `reservado_para = username`.
4. O front-end processa cada escola.
5. O JS envia `confirmado`, `nao_localizado` ou `pulado` para `PATCH /api/escolas/{id}/status`.

## SQL

O arquivo [`docs/sql/reserva_status_escolas.sql`](./sql/reserva_status_escolas.sql) contém:

- Criação das colunas e índices.
- Consulta das próximas 100 escolas disponíveis.
- Reserva das próximas 100 escolas.
- Atualização para `confirmado`, `nao_localizado` e `pulado`.
- Liberação de reservas.
- Relatórios por status e username.
