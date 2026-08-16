<?php

declare(strict_types=1);

namespace App\Email\Service;

use App\Entity\User;

final class RadarRecipientFilter
{
    /** @param iterable<object> $radars */
    public function filterForUser(User $user, iterable $radars): array
    {
        $states = $this->accessibleStateCodes($user);
        $result = [];
        foreach ($radars as $radar) {
            $state = $this->stateCode($radar);
            if ($state !== null && in_array($state, $states, true)) $result[] = $radar;
        }
        return $result;
    }

    /** @return list<string> */
    private function accessibleStateCodes(User $user): array
    {
        if (in_array('ROLE_ADMIN_GLOBAL', $user->getRoles(), true)) return ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
        $codes = [];
        foreach ($user->getBrazilianStates() as $state) {
            $code = method_exists($state, 'getUf') ? $state->getUf() : (method_exists($state, 'getSigla') ? $state->getSigla() : null);
            if ($code !== null) $codes[] = strtoupper((string) $code);
        }
        return $codes;
    }

    private function stateCode(object $radar): ?string
    {
        $state = method_exists($radar, 'getBrazilianState') ? $radar->getBrazilianState() : (method_exists($radar, 'getState') ? $radar->getState() : null);
        if (is_object($state)) return method_exists($state, 'getUf') ? strtoupper((string) $state->getUf()) : (method_exists($state, 'getSigla') ? strtoupper((string) $state->getSigla()) : null);
        return is_string($state) ? strtoupper($state) : null;
    }
}
