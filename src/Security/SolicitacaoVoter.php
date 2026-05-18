<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Solicitacao;
use App\Entity\User;
use App\Repository\SolicitacaoTipoResponsavelRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Attributes:
 *  - SOLICITACAO_COMENTAR    : pode postar mensagem no chat
 *  - SOLICITACAO_STATUS      : pode mudar o status
 *  - SOLICITACAO_VER         : pode ver a solicitação (e comentários não-internos)
 *  - SOLICITACAO_VER_INTERNO : pode ver comentários marcados como internos
 *
 * Nota: Solicitacao armazena o solicitante como strings (email, nome, usuario),
 * não como relação com User. A comparação é feita pelo e-mail do usuário logado.
 */
class SolicitacaoVoter extends Voter
{
    public const COMENTAR       = 'SOLICITACAO_COMENTAR';
    public const MUDAR_STATUS   = 'SOLICITACAO_STATUS';
    public const VER            = 'SOLICITACAO_VER';
    public const VER_INTERNO    = 'SOLICITACAO_VER_INTERNO';

    public function __construct(
        private readonly SolicitacaoTipoResponsavelRepository $responsavelRepo,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::COMENTAR,
            self::MUDAR_STATUS,
            self::VER,
            self::VER_INTERNO,
        ], true) && $subject instanceof Solicitacao;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        /** @var Solicitacao $solicitacao */
        $solicitacao = $subject;

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Solicitacao guarda o solicitante como e-mail (string), não como FK para User.
        // Comparamos o e-mail do usuário logado com o campo solicitanteEmail.
        $eSolicitante = $solicitacao->getSolicitanteEmail() === $user->getUserIdentifier();
        $eResponsavel = $this->responsavelRepo->isResponsavel($user, $solicitacao->getTipo());
        $eAdmin       = in_array('ROLE_ADMIN', $user->getRoles(), true);

        return match ($attribute) {
            // Solicitante e responsáveis do tipo podem comentar
            self::COMENTAR     => $eSolicitante || $eResponsavel || $eAdmin,

            // Apenas responsáveis do tipo e admins podem mudar status
            self::MUDAR_STATUS => $eResponsavel || $eAdmin,

            // Qualquer um dos três pode ver
            self::VER          => $eSolicitante || $eResponsavel || $eAdmin,

            // Comentários internos: apenas responsáveis/admins
            self::VER_INTERNO  => $eResponsavel || $eAdmin,

            default => false,
        };
    }
}
