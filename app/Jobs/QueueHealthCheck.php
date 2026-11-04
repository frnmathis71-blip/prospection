<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Job de contrôle : prouve que la file d'attente traite bien les jobs.
 * Envoyé par la commande « php artisan app:queue-check ».
 */
class QueueHealthCheck implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'queue-health-check';

    public function __construct(public string $token) {}

    public function handle(): void
    {
        Cache::put(self::CACHE_KEY, $this->token, now()->addHour());
    }
}
