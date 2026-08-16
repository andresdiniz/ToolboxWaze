# E-mails transacionais com Resend

Use `php bin/console app:resend:check-config` para validar as variáveis sem enviar mensagens.

Para testar o fluxo assíncrono:

```bash
php bin/console app:resend:test-email destinatario@exemplo.com
php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
```

O comando de teste apenas despacha uma mensagem; o worker é quem realiza o envio. Nunca versione `RESEND_API_KEY`.
