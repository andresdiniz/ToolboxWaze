<?php

namespace App\Security\Voter;

use App\Entity\Solicitacao;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class SolicitacaoVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, ['SOLICITACAO_VER', 'SOLICITACAO_RESOLVER'])
            && $subject instanceof Solicitacao;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) return false;

        /** @var Solicitacao $solicitacao */
        $solicitacao = $subject;

        if (in_array('ROLE_ADMIN', $user->getRoles())) return true;

        return match ($attribute) {
            'SOLICITACAO_VER'      => $solicitacao->getResponsaveis()->contains($user),
            'SOLICITACAO_RESOLVER' => $solicitacao->isPendente() && $solicitacao->getResponsaveis()->contains($user),
            default                => false,
        };
    }
}
