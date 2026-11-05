<?php

namespace App\Providers;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Horizon n'est jamais ouvert sans connexion, y compris en local
     * (le comportement par défaut de Horizon l'ouvre à tous en local).
     * Les visiteurs sont redirigés vers la connexion par le middleware « auth »
     * déclaré dans config/horizon.php.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Seul un utilisateur connecté peut consulter Horizon, et il doit avoir
     * activé sa double authentification quand celle-ci est obligatoire.
     */
    protected function gate(): void
    {
        // Mutualisé : pas de Redis, donc pas de Horizon (état de la file sur le tableau de bord).
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user !== null
            && config('prospection.hosting') !== 'shared'
            && (! TwoFactor::isRequired() || $user->hasAppAuthenticationEnabled()));
    }
}
