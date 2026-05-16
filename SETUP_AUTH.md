# Setup — Sistema de Autenticação

## 1. Instalar dependências

```bash
composer require knpuniversity/oauth2-client-bundle league/oauth2-google symfony/mailer
```

## 2. Rodar a migration

```bash
php bin/console doctrine:migrations:migrate
```

## 3. Criar o primeiro admin

Após rodar a migration, crie o primeiro usuário admin manualmente:

```bash
php bin/console doctrine:query:sql "
  INSERT INTO user (email, name, roles, password, status, created_at, approved_at)
  VALUES (
    'admin@toolboxwaze.com.br',
    'Administrador',
    '[\"ROLE_ADMIN\"]',
    NULL,
    'approved',
    NOW(),
    NOW()
  )
"
```

Depois defina a senha via terminal:
```bash
php bin/console security:hash-password
# Cole o hash gerado no campo password do usuário admin
```

## 4. Configurar Google OAuth

### Criar credenciais no Google Cloud Console:
1. Acesse https://console.cloud.google.com/
2. Crie um projeto (ou use um existente)
3. Vá em **APIs & Services → Credentials**
4. Clique em **Create Credentials → OAuth 2.0 Client IDs**
5. Tipo: **Web application**
6. Authorized redirect URIs:
   - `http://localhost:8000/connect/google/callback` (dev)
   - `https://seudominio.com.br/connect/google/callback` (prod)

### Adicionar ao `.env.local`:

```env
GOOGLE_CLIENT_ID=seu_client_id_aqui.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=seu_client_secret_aqui
```

## 5. Configurar Mailer (recuperação de senha)

No `.env.local`:

```env
# Exemplo com Gmail SMTP
MAILER_DSN=smtp://usuario:senha@smtp.gmail.com:587?encryption=tls

# Ou com Mailtrap para desenvolvimento
MAILER_DSN=smtp://usuario:senha@sandbox.smtp.mailtrap.io:2525
```

## 6. Fluxo de aprovação

1. Usuário se cadastra (`/register`) ou faz login com Google
2. Status fica como `pending`
3. Admin acessa `/admin/users` e vê os pendentes
4. Admin clica em **Aprovar** → status vira `approved`
5. Usuário já pode fazer login normalmente

## Rotas criadas

| Rota | URL | Acesso |
|------|-----|--------|
| `app_home` | `/` | Público |
| `app_login` | `/login` | Público |
| `app_logout` | `/logout` | Autenticado |
| `app_register` | `/register` | Público |
| `app_google_connect` | `/connect/google` | Público |
| `app_google_callback` | `/connect/google/callback` | Público |
| `app_forgot_password` | `/forgot-password` | Público |
| `app_reset_password` | `/reset-password/{token}` | Público |
| `app_dashboard` | `/dashboard` | ROLE_USER |
| `admin_user_index` | `/admin/users` | ROLE_ADMIN |
| `admin_user_approve` | `/admin/users/{id}/approve` | ROLE_ADMIN |
| `admin_user_reject` | `/admin/users/{id}/reject` | ROLE_ADMIN |
| `admin_user_toggle_admin` | `/admin/users/{id}/toggle-admin` | ROLE_ADMIN |
