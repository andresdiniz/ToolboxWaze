# Comandos — Doctrine Migrations

```bash
# 1. Criar a migration a partir das mudanças nas entidades
php bin/console doctrine:migrations:diff

# 2. Revisar o arquivo gerado em migrations/

# 3. Executar
php bin/console doctrine:migrations:migrate --no-interaction
```
