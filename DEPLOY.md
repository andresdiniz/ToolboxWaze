# Deploy — ToolboxWaze

## Variáveis de ambiente no servidor

Crie o arquivo `.env.local` na raiz do projeto no servidor com:

```dotenv
APP_ENV=prod
APP_DEBUG=false
```

> **Importante:** o arquivo `.env` do repositório define `APP_ENV=dev` para facilitar o desenvolvimento local.  
> Sem o `.env.local` no servidor, o Twig tenta usar `FilesystemCache` do cache de desenvolvimento,  
> resultando em erro `ClassNotFoundError: FilesystemCache`.

## Após cada `git pull` no servidor

```bash
# Limpar cache de produção
php bin/console cache:clear --env=prod

# Compilar assets (se necessário)
php bin/console asset-map:compile
```

## Checklist de deploy

- [ ] `.env.local` com `APP_ENV=prod` e `APP_DEBUG=false` criado no servidor
- [ ] `php bin/console cache:clear --env=prod` executado
- [ ] Permissões de escrita em `var/` confirmadas (`chmod -R 775 var/`)
- [ ] E-mail SMTP configurado em `.env.local` (`MAILER_DSN=smtp://...`)
