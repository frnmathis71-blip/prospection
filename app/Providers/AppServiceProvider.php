<?php

namespace App\Providers;

use App\Services\Inbox\ImapMailbox;
use App\Services\Inbox\Mailbox;
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
        // Boîte d'envoi lue en IMAP (M10) ; remplacée par une boîte simulée dans les tests.
        $this->app->bind(Mailbox::class, ImapMailbox::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enrichissements (OpenStreetMap + devinette de domaine) : usage raisonnable des services gratuits.
        RateLimiter::for('enrichment', fn () => Limit::perMinute((int) config('prospection.enrichments_per_minute', 20)));

        // Analyse des sites (M5) : une visite par site, débit raisonnable.
        RateLimiter::for('analysis', fn () => Limit::perMinute((int) config('prospection.website_analysis.per_minute', 20)));

        // Recherche d'emails (M7) : quelques pages par site, débit raisonnable.
        RateLimiter::for('email_search', fn () => Limit::perMinute((int) config('prospection.email_search.per_minute', 10)));

        // Signal de vie du worker pour le tableau de bord (à chaque tour de boucle et à chaque job).
        Queue::looping(fn () => WorkerHeartbeat::beat());
        Queue::before(fn () => WorkerHeartbeat::beat());
    }
}
