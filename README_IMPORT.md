# Importação de Radares — Arquitetura Hostinger

## Problema

A Hostinger (shared hosting) bloqueia `proc_open`, `shell_exec` e funções de execução de processos no PHP web.

## Solução: Symfony Messenger + Doctrine Transport

O fluxo funciona assim:

```
Browser
  │
  ├── GET /admin/estados/{id}/importar/start
  │     └── bus->dispatch(ImportRadaresMessage)  ← grava na tabela messenger_messages
  │     └── retorna JSON { token, poll_url }
  │
  └── SSE /admin/estados/importar/poll?token=XXX
        └── aguarda var/log/import_{UF}_{token}.log aparecer
        └── stream linha a linha até .done ou .fail sentinela

Cron Job (Hostinger)
  └── php bin/console messenger:consume async --limit=1 --time-limit=300 --env=prod
        └── ImportRadaresHandler->__invoke()
              └── executa app:import-radares in-process
              └── grava output em var/log/import_{UF}_{token}.log
              └── toca .done ou .fail
```

## Configurar Cron na Hostinger

### Painel hPanel > Avançado > Cron Jobs

Adicionar um cron a cada 1 minuto:

```
* * * * * /opt/alt/php85/usr/bin/php /home/u629736858/domains/acheireviews.com.br/public_html/wazetoolbox/bin/console messenger:consume async --limit=1 --time-limit=55 --env=prod --no-debug 2>> /home/u629736858/domains/acheireviews.com.br/public_html/wazetoolbox/var/log/messenger_cron.log
```

**Ou a cada 5 minutos** (aceitável se importações não são urgentes):
```
*/5 * * * * /opt/alt/php85/usr/bin/php /home/u629736858/domains/acheireviews.com.br/public_html/wazetoolbox/bin/console messenger:consume async --limit=5 --time-limit=270 --env=prod --no-debug
```

## Setup inicial

```bash
# 1. Pull das alterações
git pull origin main

# 2. Limpar cache
php bin/console cache:clear --env=prod

# 3. Criar tabela da fila (auto_setup:true faz isso automaticamente,
#    mas pode rodar manualmente):
php bin/console doctrine:schema:update --dump-sql
# Se aparecer CREATE TABLE messenger_messages, está tudo certo.
# Para aplicar:
php bin/console doctrine:schema:update --force

# 4. Configurar o .env.local ou .env com:
# MESSENGER_TRANSPORT_DSN=doctrine://default
```

## Variável de ambiente

No `.env` (ou `.env.local`):
```dotenv
MESSENGER_TRANSPORT_DSN=doctrine://default
```

## Testando manualmente

```bash
# Disparar uma importação pela fila:
php bin/console messenger:consume async --limit=1 --env=prod -vv
```
