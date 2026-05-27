# ToolboxWaze

Sistema de gestão de editores Waze — monitoramento de radares, solicitações e links Waze.

## Requisitos

- PHP 8.2+
- Composer 2.x
- MariaDB 10.6+ (ou MySQL 8+)
- Symfony CLI (opcional, mas recomendado)
- Node.js 20+ / npm (para Asset Mapper)

## Instalação

```bash
# 1. Clone o repositório
git clone https://github.com/andresdiniz/ToolboxWaze.git
cd ToolboxWaze

# 2. Instale as dependências PHP
composer install

# 3. Copie o arquivo de ambiente e ajuste as variáveis
cp .env .env.local
# Edite DATABASE_URL, MAILER_DSN e APP_SECRET em .env.local

# 4. Crie o banco e rode as migrations
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# 5. (Opcional) Instale assets front-end
php bin/console importmap:install

# 6. Inicie o servidor de desenvolvimento
symfony server:start
```

## Variáveis de ambiente obrigatórias

| Variável | Descrição |
|---|---|
| `DATABASE_URL` | DSN do banco MariaDB/MySQL |
| `APP_SECRET` | Chave secreta da aplicação Symfony |
| `MAILER_DSN` | DSN do serviço de e-mail (ex: `smtp://...`) |

## Deploy

Consulte o arquivo **DEPLOY.md** para instruções de deploy em produção.

## Autenticação

Consulte o arquivo **SETUP_AUTH.md** para configuração de OAuth e níveis de acesso.

## Testes

```bash
php bin/phpunit
```

## Estrutura principal

```
src/
  Controller/       # Controllers HTTP
    Admin/          # Controllers restritos a administradores
  Entity/           # Entidades Doctrine
  Repository/       # Repositórios com queries encapsuladas
  Service/          # Lógica de negócio (Stats, WazeLink, etc.)
  Message/          # Mensagens assíncronas (Symfony Messenger)
templates/          # Templates Twig
migrations/         # Migrations do Doctrine
tests/              # Testes PHPUnit
```
