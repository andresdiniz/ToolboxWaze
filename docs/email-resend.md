# E-mails transacionais com Resend

## Estado atual

A camada reutilizável já possui:

- Transporte `ResendEmailTransport`.
- Contrato `EmailTransportInterface`.
- `EmailNotificationService` para renderização Twig.
- `EmailDeliveryService` para capturar falhas.
- `SendEmailMessage` e handler Messenger.
- Logger estruturado de tentativas.
- Templates de usuário, administrador, reset e digest.
- Comandos de diagnóstico e renderização local.

## Comandos de validação

```bash
php bin/console app:resend:check-config
php bin/console app:email:check-template email/welcome_user.html.twig
php bin/console app:email:check-template email/welcome_admin.html.twig
php bin/console app:email:check-template email/password_reset.html.twig
php bin/console app:email:check-template email/radar_weekly_digest.html.twig
php bin/console app:resend:test-email destinatario@exemplo.com
php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
```

## Pendências de integração de domínio

Os fluxos de cadastro, reset de senha e importação de radares ainda precisam ser conectados depois da confirmação do mapeamento exato das entidades e dos controllers no ambiente local. Não crie migrações presumindo nomes de relações.

## Produção

1. Configure `RESEND_API_KEY` somente no ambiente do servidor.
2. Configure `MAILER_FROM_EMAIL`, `MAILER_FROM_NAME` e `APP_PUBLIC_URL`.
3. Valide SPF, DKIM e DMARC no domínio do Resend.
4. Configure o transporte `async` do Messenger.
5. Mantenha um worker ativo.
6. Execute o diagnóstico antes do primeiro envio.
7. Execute um envio de teste para um endereço controlado.
8. Monitore logs e falhas do worker.

Nunca versione chaves, tokens de reset ou conteúdo sensível de usuários.
