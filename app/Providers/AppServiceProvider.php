<?php

namespace App\Providers;

use App\Support\WorkerHeartbeat;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enrichissements (OpenStreetMap + devinette de domaine) : usage raisonnable des services gratuits.
        RateLimiter::for('enrichment', fn () => Limit::perMinute((int) config('prospection.enrichments_per_minute', 20)));

        // Signal de vie du worker pour le tableau de bord (à chaque tour de boucle et à chaque job).
        Queue::looping(fn () => WorkerHeartbeat::beat());
        Queue::before(fn () => WorkerHeartbeat::beat());
    }
}
