<?php

namespace App\Message;

/**
 * Mensagem Messenger para envio assíncrono de e-mails de solicitação.
 *
 * @param string   $tipo           'confirmacao' | 'responsavel' | 'resolucao' | 'comentario'
 * @param int      $solicitacaoId  ID da solicitação
 * @param int|null $destinatarioId ID do User destinatário (para tipo=responsavel)
 */
final readonly class EnviarEmailSolicitacao
{
    public function __construct(
        public string $tipo,
        public int    $solicitacaoId,
        public ?int   $destinatarioId = null,
    ) {}
}
