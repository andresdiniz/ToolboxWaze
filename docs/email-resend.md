# E-mails transacionais com Resend

## Validação local

```bash
php bin/console app:resend:check-config
php bin/console app:email:check-template email/welcome_user.html.twig
php bin/console app:email:check-template email/welcome_admin.html.twig
php bin/console app:email:check-template email/password_reset.html.twig
php bin/console app:email:check-template email/radar_weekly_digest.html.twig
```

O comando de template renderiza o HTML localmente e não envia e-mail.

## Envio assíncrono

```bash
php bin/console app:resend:test-email destinatario@exemplo.com
php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
```
