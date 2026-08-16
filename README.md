# Toolbox Waze

Sistema web para monitoramento, consulta e gestão de radares, escolas e pontos de interesse relacionados à mobilidade rodoviária brasileira.

O projeto centraliza dados importados de fontes externas, disponibiliza dashboards e consultas para usuários autorizados e oferece ferramentas administrativas para revisão, auditoria e manutenção das informações.

## Visão geral

O Toolbox Waze foi desenvolvido com Symfony e Doctrine ORM, com uma arquitetura orientada a serviços, comandos de console e processamento assíncrono.

Principais capacidades:

- Importação e atualização de radares.
- Consulta de radares por estado, rodovia, município, velocidade e localização.
- Integração e análise de links do Waze.
- Gestão de usuários, permissões e estados autorizados.
- Área administrativa com aprovação, rejeição e atualização de permissões.
- Cadastro e consulta de escolas do INEP.
- Monitoramento de postos, solicitações e notificações.
- Dashboards e indicadores operacionais.
- Filas Symfony Messenger para tarefas assíncronas.
- E-mails transacionais preparados para Resend.

## Stack

- PHP 8.2+.
- Symfony.
- Doctrine ORM e migrations.
- MySQL/MariaDB.
- Twig.
- Symfony Messenger.
- Symfony HttpClient.
- PHPUnit.
- Docker Compose opcional para desenvolvimento.
- Bootstrap/JavaScript via AssetMapper ou Importmap, conforme o módulo.

## Requisitos

- PHP compatível com a versão definida em `composer.json`.
- Composer.
- MySQL ou MariaDB.
- Extensões PHP habilitadas pelo Symfony e Doctrine.
- Node.js não é obrigatório para todos os módulos, mas pode ser necessário conforme a alteração de front-end.
- Redis, banco ou outro transporte configurado caso o ambiente use fila assíncrona.

## Instalação local

Clone o repositório e instale as dependências:

```bash
git clone https://github.com/andresdiniz/ToolboxWaze.git
cd ToolboxWaze
composer install
```

Crie o ambiente local sem alterar o `.env` versionado:

```bash
cp .env.example .env.local
```

Configure no `.env.local`:

```dotenv
APP_ENV=dev
APP_SECRET=uma-chave-local-segura
DATABASE_URL="mysql://usuario:senha@localhost:3306/toolbox_waze?serverVersion=mariadb-10.6&charset=utf8mb4"
```

Crie o banco e execute as migrations:

```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
```

Limpe o cache e inicie o servidor:

```bash
php bin/console cache:clear
symfony server:start -d
```

Se não utilizar Symfony CLI:

```bash
php -S 127.0.0.1:8000 -t public
```

## Dados iniciais

Para semear os estados brasileiros:

```bash
php bin/console app:seed:brazilian-states
```

Consulte `README_IMPORT.md` para os fluxos de importação de dados.

## Comandos importantes

### Radares

```bash
php bin/console app:import:radar
php bin/console app:import:radar-medidores
php bin/console app:notificar:radares-vencidos
```

Os nomes exatos dos comandos podem ser consultados com:

```bash
php bin/console list app
```

### Filas

Execute o worker conforme o transporte definido em `config/packages/messenger.yaml`:

```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
```

### E-mails com Resend

Configure as variáveis no ambiente do servidor ou em `.env.local`:

```dotenv
RESEND_API_KEY=re_xxxxxxxxx
MAILER_FROM_EMAIL=no-reply@seudominio.com.br
MAILER_FROM_NAME="Toolbox Waze"
APP_PUBLIC_URL=https://seudominio.com.br
```

Valide sem enviar:

```bash
php bin/console app:resend:check-config
```

Valide um template localmente:

```bash
php bin/console app:email:check-template email/welcome_user.html.twig
```

Despache um teste para a fila:

```bash
php bin/console app:resend:test-email destinatario@exemplo.com
php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
```

Nunca versione `RESEND_API_KEY`.

## Estrutura do projeto

```text
src/
├── Command/              Comandos Symfony e rotinas operacionais
├── Controller/            Controllers HTTP e áreas administrativas
├── Entity/                Entidades Doctrine
├── Repository/            Consultas e acesso a dados
├── Service/               Regras de negócio e integrações
├── Message/               Mensagens do Messenger
├── MessageHandler/        Processadores assíncronos
├── Email/                 Transporte, DTOs e serviços de e-mail
└── Security/              Autenticação e autorização

templates/                 Templates Twig da aplicação e dos e-mails
migrations/                 Migrações Doctrine
config/                     Configuração do Symfony
cron/                       Scripts de execução agendada
tests/                      Testes automatizados
docs/                       Documentação técnica
```

## Arquitetura de e-mail

A integração de e-mail foi separada em camadas para permitir trocar o provedor no futuro:

```text
Mensagem/serviço de domínio
        ↓
EmailNotificationService
        ↓
EmailDeliveryService
        ↓
EmailTransportInterface
        ↓
ResendEmailTransport
```

O transporte não deve ser chamado diretamente por controllers ou comandos de negócio. Use mensagens Messenger para envios que não precisam bloquear a requisição.

Templates disponíveis incluem:

- Boas-vindas ao usuário.
- Notificação administrativa de nova conta.
- Reset de senha.
- Confirmação de alteração de senha.
- Resumo semanal de radares.

## Cron e importações

O diretório `cron/` contém scripts de execução agendada. Antes de configurar produção:

1. Verifique o caminho absoluto do PHP.
2. Verifique o caminho absoluto do projeto.
3. Garanta permissão de execução.
4. Redirecione stdout e stderr para logs.
5. Evite executar duas importações simultaneamente.
6. Configure o worker do Messenger separadamente.

Exemplo genérico:

```cron
*/15 * * * * cd /var/www/toolbox-waze && php bin/console app:import:radar --env=prod >> var/log/cron-radar.log 2>&1
```

Use os comandos efetivamente registrados pelo projeto (`php bin/console list app`) antes de copiar a linha para produção.

## Deploy

Consulte [DEPLOY.md](DEPLOY.md) para o procedimento específico de publicação. Em produção, normalmente é necessário:

```bash
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console cache:clear --env=prod
```

Após cada deploy, valide:

```bash
php bin/console about
php bin/console lint:twig templates
php bin/console app:resend:check-config
```

Se houver Messenger, reinicie o worker após a publicação para carregar o novo código.

## Segurança

- Nunca publique `.env`, tokens, senhas ou chaves de API.
- Use `.env.local` ou variáveis do servidor para segredos.
- Mantenha `APP_SECRET` forte e exclusivo por ambiente.
- Use HTTPS em produção.
- Restrinja rotas administrativas por roles.
- Monitore falhas de importação e de fila.
- Não coloque tokens de reset ou dados sensíveis em logs.
- Configure SPF, DKIM e DMARC no domínio usado pelo Resend.

## Testes e qualidade

Execute os testes antes de publicar:

```bash
php bin/phpunit
php bin/console lint:twig templates
php bin/console lint:yaml config
```

Para alterações de banco:

```bash
php bin/console doctrine:schema:validate
php bin/console doctrine:migrations:diff
```

Revise a migration antes de executá-la em produção.

## Documentação adicional

- [DEPLOY.md](DEPLOY.md) — publicação e operação.
- [README_IMPORT.md](README_IMPORT.md) — importação de dados.
- [SETUP_AUTH.md](SETUP_AUTH.md) — configuração de autenticação.
- [docs/email-resend.md](docs/email-resend.md) — e-mails transacionais e Resend.

## Status

O projeto está em desenvolvimento ativo. Antes de ativar uma integração em produção, valide as variáveis de ambiente, execute os testes e confirme o comportamento dos workers e dos comandos agendados.

## Licença

Consulte os termos de distribuição definidos pelo responsável pelo repositório.
