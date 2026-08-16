# E-mails transacionais com Resend

A aplicação possui uma camada reutilizável de transporte, independente do provedor:

- `App\\Email\\Contract\\EmailTransportInterface`: contrato do transporte.
- `App\\Email\\Transport\\ResendEmailTransport`: implementação para o Resend.
- `App\\Email\\Service\\EmailNotificationService`: renderiza templates Twig e envia mensagens.
- `App\\Email\\Service\\EmailDeliveryService`: entrega e captura falhas.
- `App\\Email\\Message\\SendEmailMessage`: mensagem para envio assíncrono.
- `App\\Email\\MessageHandler\\SendEmailMessageHandler`: handler do Messenger.
- `App\\Command\\ResendConfigCheckCommand`: valida configuração sem enviar mensagem.

## Diagnóstico

Execute:

```bash
php bin/console app:resend:check-config
```

O comando não faz chamadas externas e nunca expõe o valor da chave, somente informa se ela existe.

## Variáveis obrigatórias

```dotenv
RESEND_API_KEY=re_xxxxxxxxx
MAILER_FROM_EMAIL=no-reply@seudominio.com.br
MAILER_FROM_NAME="Toolbox Waze"
APP_PUBLIC_URL=https://seudominio.com.br
```

Nunca versione `RESEND_API_KEY`. Configure-a no servidor, no painel de hospedagem ou nos secrets do CI/CD.
