# E-mails transacionais com Resend

A camada reutilizável usa `EmailTransportInterface`, `ResendEmailTransport`, `EmailNotificationService` e mensagens Messenger. Configure `RESEND_API_KEY`, `MAILER_FROM_EMAIL` e `MAILER_FROM_NAME` no ambiente; nunca versione a chave real. `ROLE_ADMIN_GLOBAL` recebe os 27 estados e os demais usuários somente os estados associados. O envio deve ser executado por worker Messenger.
