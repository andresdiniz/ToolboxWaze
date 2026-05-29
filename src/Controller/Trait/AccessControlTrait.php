<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Helpers de controle de acesso por permissão de área e UF.
 *
 * Requer que a classe que usa este trait estenda AbstractController
 * (para ter acesso a getUser(), addFlash() e redirectToRoute()).
 */
trait AccessControlTrait
{
    /**
     * Lança 403 se o usuário não tiver a permissão de área informada.
     * Admins passam sempre.
     */
    private function requirePermission(string $permission): void
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if ($user === null || !$user->hasPermission($permission)) {
            throw new AccessDeniedHttpException(
                'Você não tem permissão para acessar esta área.'
            );
        }
    }

    /**
     * Lança 403 se o usuário não tiver acesso à UF do registro.
     * Admins passam sempre.
     *
     * @param string|null $uf  Sigla da UF do registro (ex: 'MG'). Null/vazio = sem restrição.
     */
    private function requireUfAccess(?string $uf): void
    {
        if ($uf === null || $uf === '') {
            return;
        }

        /** @var User|null $user */
        $user = $this->getUser();
        if ($user === null || !$user->canAccessUf($uf)) {
            throw new AccessDeniedHttpException(
                "Você não tem acesso a registros do estado {$uf}."
            );
        }
    }

    /**
     * Retorna a cláusula WHERE e os parâmetros para restringir a consulta
     * às UFs permitidas ao usuário. Se o usuário tiver acesso a todos os
     * estados (admin ou allowedUfs === null), retorna strings/arrays vazios.
     *
     * @param string $column  Nome da coluna de UF na query (ex: 'r.sigla_uf', 'e.uf').
     * @return array{clause: string, params: list<string>}
     */
    private function enforceUfsOnQuery(string $column): array
    {
        /** @var User|null $user */
        $user      = $this->getUser();
        $allowedUfs = $user?->getUfsForQuery();

        if ($allowedUfs === null) {
            return ['clause' => '', 'params' => []];
        }

        if (empty($allowedUfs)) {
            // Usuário sem nenhuma UF permitida: retorna condição impossível
            return ['clause' => '1=0', 'params' => []];
        }

        $placeholders = implode(',', array_fill(0, count($allowedUfs), '?'));
        return [
            'clause' => "{$column} IN ({$placeholders})",
            'params' => $allowedUfs,
        ];
    }

    /**
     * Retorna a lista de UFs permitidas para passar ao template
     * (usado para limitar as opções do filtro de UF na view).
     * Retorna null quando o usuário tem acesso irrestrito.
     *
     * @return list<string>|null
     */
    private function allowedUfsForView(): ?array
    {
        /** @var User|null $user */
        $user = $this->getUser();
        return $user?->getUfsForQuery();
    }
}
