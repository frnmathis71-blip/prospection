<?php

namespace App\Support;

/**
 * Règle unique décidant si la double authentification est obligatoire.
 *
 * Elle peut être coupée en local (AUTH_TWO_FACTOR_REQUIRED=false), mais reste
 * toujours imposée en production, quel que soit le réglage.
 */
class TwoFactor
{
    public static function isRequired(): bool
    {
        return app()->isProduction() || (bool) config('auth.two_factor_required', true);
    }
}
