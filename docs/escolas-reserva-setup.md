# Expiração de reservas de escolas: instalação

Arquivos desta pasta (copiar para `src/` do projeto Symfony):

- `src/Service/EscolaReservaService.php` — regras de reservar, liberar, renovar e expirar (com log de tempo)
- `src/Controller/EscolaApiController.php` — controller completo usando o serviço
- `src/Command/LiberarEscolasExpiradasCommand.php` — comando agendado que expira reservas

## 1. Entidade: nova coluna `reservado_em`

Em `src/Entity/EscolaInep.php`:

```php
#[ORM\Column(type: 'datetime_immutable', nullable: true)]
private ?\DateTimeImmutable $reservadoEm = null;

public function getReservadoEm(): ?\DateTimeImmutable
{
    return $this->reservadoEm;
}

public function setReservadoEm(?\DateTimeImmutable $reservadoEm): static
{
    $this->reservadoEm = $reservadoEm;

    return $this;
}
```

Índice recomendado para as consultas de reserva e expiração (ajuste os nomes das colunas ao seu schema):

```php
#[ORM\Index(name: 'idx_escola_uf_status_reserva', columns: ['uf', 'status', 'reservado_em'])]
```

Depois:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Reservas que já existem hoje ficam com `reservado_em = NULL` e são tratadas como vencidas na primeira execução do comando.

## 2. Canal de log `escolas` (config/packages/monolog.yaml)

O serviço e o comando exigem este canal (usam `monolog.logger.escolas`).

```yaml
monolog:
    channels: ['escolas']
    handlers:
        escolas:
            type: rotating_file
            path: '%kernel.logs_dir%/escolas.log'
            level: info
            max_files: 30
            channels: ['escolas']
            formatter: monolog.formatter.json
```

Gera `var/log/escolas-AAAA-MM-DD.log`, uma linha JSON por evento, mantendo 30 dias.

## 3. Agendar a expiração

```bash
# crontab -e  (a cada 5 minutos)
*/5 * * * * cd /var/www/app && php bin/console app:escolas:liberar-expiradas --ttl=30 --env=prod --no-debug >/dev/null 2>&1
```

Testar sem alterar nada:

```bash
php bin/console app:escolas:liberar-expiradas --ttl=30 --dry-run
php bin/console app:escolas:liberar-expiradas --uf=MG --dry-run
```

O TTL padrão (30 min) fica em `EscolaReservaService::TTL_MINUTOS`. Mesmo sem o cron, o endpoint `reservar` já reaproveita reservas vencidas de outros usuários.

## 4. O que fica registrado

Cada linha tem `context.evento` e `context.duracao_ms`:

| evento | quando | campos extras |
|---|---|---|
| `reserva` | POST `/reservar` | uf, username, limite, ttl_minutos, total, retomadas, novas, expiradas_reaproveitadas, consulta (DQL) |
| `liberacao` | POST `/liberar` | uf, username, liberadas, consulta |
| `renovacao` | POST `/renovar` | uf, username, renovadas, consulta |
| `comando_expiracao` | comando agendado | comando (argv completo), entrada, ttl_minutos, uf, dry_run, iniciado_em, finalizado_em, liberadas, corte, consulta, memoria_pico_mb |
| `comando_expiracao_falhou` | erro no comando | comando, erro, classe, duracao_ms |

## 5. Análise futura (jq)

```bash
# Tempo médio, máximo e quantidade por tipo de evento
jq -s 'map(select(.context.evento)) | group_by(.context.evento)
  | map({evento: .[0].context.evento, n: length,
         media_ms: ((map(.context.duracao_ms) | add) / length),
         max_ms: (map(.context.duracao_ms) | max)})' var/log/escolas-*.log

# Histórico dos comandos executados e quantas reservas liberaram
jq -r 'select(.context.evento == "comando_expiracao")
  | [.datetime, .context.comando, .context.duracao_ms, .context.liberadas] | @tsv' var/log/escolas-*.log

# Reservas mais lentas (acima de 500 ms)
jq -c 'select(.context.evento == "reserva" and .context.duracao_ms > 500)' var/log/escolas-*.log

# Quantas reservas expiradas foram reaproveitadas por dia
jq -r 'select(.context.evento == "reserva" and .context.expiradas_reaproveitadas > 0)
  | [.datetime[0:10], .context.expiradas_reaproveitadas] | @tsv' var/log/escolas-*.log
```

## 6. Endpoint novo: `POST /api/escolas/uf/{uf}/renovar`

Corpo `{"username": "..."}`. Estende o prazo das reservas ativas do usuário. O userscript pode chamá-lo
enquanto houver atividade (por exemplo a cada 10 minutos), para que a reserva só expire de verdade
quando a pessoa realmente parou de trabalhar.
