# E-mails transacionais com Resend

A camada de e-mail é reutilizável e independente do domínio. Templates disponíveis:

- `email/welcome_user.html.twig`: boas-vindas ao usuário.
- `email/welcome_admin.html.twig`: aviso aos administradores sobre nova conta.
- `email/password_reset.html.twig`: redefinição de senha.
- `email/password_reset_success.html.twig`: confirmação de alteração.
- `email/radar_weekly_digest.html.twig`: resumo semanal agrupado.

Valide a configuração com `php bin/console app:resend:check-config` e teste o envio assíncrono com `php bin/console app:resend:test-email destinatario@exemplo.com`.
