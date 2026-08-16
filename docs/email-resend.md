# E-mails transacionais com Resend

A aplicação possui uma camada reutilizável de transporte, independente do provedor:

- `App\\Email\\Contract\\EmailTransportInterface`: contrato do transporte.
- `App\\Email\\Transport\\ResendEmailTransport`: implementação para o Resend.
- `App\\Email\\Service\\EmailNotificationService`: renderiza templates Twig e envia mensagens.
- `App\\Email\\Message\\SendEmailMessage`: mensagem para envio assíncrono.
- `App\\Email\\MessageHandler\\SendEmailMessageHandler`: handler do Messenger.

## Variáveis obrigatórias

```dotenv
RESEND_API_KEY=re_xxxxxxxxx
MAILER_FROM_EMAIL=no-reply@seudominio.com.br
MAILER_FROM_NAME="Toolbox Waze"
APP_PUBLIC_URL=https://seudominio.com.br
```

Nunca versione `RESEND_API_KEY`. Configure-a no servidor, no painel de hospedagem ou nos secrets do CI/CD.

## Domínio

Antes do envio em produção, valide o domínio no Resend e configure os registros SPF, DKIM e DMARC indicados pelo provedor.

## Worker

O envio assíncrono só será processado se houver um worker do Messenger ativo. O comando normalmente utilizado é:

```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
```

Configure o transporte `async` conforme o `config/packages/messenger.yaml` do ambiente.
