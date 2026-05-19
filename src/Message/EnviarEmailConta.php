<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem Messenger para todos os e-mails relacionados a contas de usuário.
 *
 * Tipos disponíveis:
 *   - 'criacao'           → e-mail enviado ao usuário com link de ativação/senha
 *   - 'conta_criada'      → confirmação após o usuário ativar/confirmar a conta
 *   - 'solicitacao_admin' → notificação ao(s) admin(s) quando alguém solicita acesso
 *
 * Exemplo de uso:
 *   $bus->dispatch(new EnviarEmailConta('criacao', $user->getId()));
 *   $bus->dispatch(new EnviarEmailConta('solicitacao_admin', $user->getId(), $adminId));
 */
final class EnviarEmailConta
{
    public function __construct(
        /** @var string 'criacao' | 'conta_criada' | 'solicitacao_admin' */
        public readonly string $tipo,

        /** ID do usuário recém-criado / que solicitou acesso */
        public readonly int $userId,

        /**
         * ID do admin destinatário (obrigatório quando tipo='solicitacao_admin').
         * Null para tipos que enviam direto ao próprio usuário.
         */
        public readonly ?int $adminId = null,
    ) {}
}
