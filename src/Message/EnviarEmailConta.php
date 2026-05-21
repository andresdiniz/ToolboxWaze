<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem Messenger para todos os e-mails relacionados a contas de usuário.
 *
 * Tipos disponíveis:
 *   - 'conta_criada'          → confirmação ao usuário após criar a conta (aguardando aprovação)
 *   - 'solicitacao_admin'     → notificação ao admin quando alguém solicita acesso
 *   - 'aprovado'              → e-mail ao usuário informando que a conta foi aprovada
 *   - 'rejeitado'             → e-mail ao usuário informando que a conta foi rejeitada
 *   - 'permissoes_atualizadas'→ e-mail ao usuário informando que as permissões foram alteradas
 */
final class EnviarEmailConta
{
    public function __construct(
        /**
         * @var string 'conta_criada'|'solicitacao_admin'|'aprovado'|'rejeitado'|'permissoes_atualizadas'
         */
        public readonly string $tipo,

        /** ID do usuário que criou/sofreu alteração */
        public readonly int $userId,

        /**
         * ID do admin destinatário — obrigatório apenas quando tipo='solicitacao_admin'.
         * Para demais tipos, null.
         */
        public readonly ?int $adminId = null,
    ) {}
}
