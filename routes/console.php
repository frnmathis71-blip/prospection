<?php

use App\Jobs\QueueHealthCheck;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('app:queue-check', function () {
    $token = (string) Str::uuid();

    QueueHealthCheck::dispatch($token);

    $this->info("Job de contrôle envoyé en file (jeton {$token}).");
    $this->line('Vérifiez son exécution dans Horizon, ou avec : php artisan tinker --execute="echo cache(\'queue-health-check\');"');
})->purpose("Envoie un job de contrôle dans la file d'attente");
