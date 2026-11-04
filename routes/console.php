<?php

use App\Jobs\QueueHealthCheck;
use App\Jobs\RunCompanySearch;
use App\Models\Search;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('app:queue-check', function () {
    $token = (string) Str::uuid();

    QueueHealthCheck::dispatch($token);

    $this->info("Job de contrôle envoyé en file (jeton {$token}).");
    $this->line('Vérifiez son exécution dans Horizon, ou avec : php artisan tinker --execute="echo cache(\'queue-health-check\');"');
})->purpose("Envoie un job de contrôle dans la file d'attente");

Artisan::command('app:run-recurring-searches {recurrence : weekly ou monthly}', function (string $recurrence) {
    $searches = Search::query()->where('recurrence', $recurrence)->get();

    $searches->each(fn (Search $search) => RunCompanySearch::dispatch($search));

    $this->info($searches->count().' recherche(s) « '.$recurrence.' » mise(s) en file.');
})->purpose('Relance les recherches récurrentes (planifié)');

// Recherches récurrentes (mission M4) : « Plombiers 71 — chaque lundi ».
Schedule::command('app:run-recurring-searches weekly')->weeklyOn(1, '07:00')->timezone('Europe/Paris');
Schedule::command('app:run-recurring-searches monthly')->monthlyOn(1, '07:00')->timezone('Europe/Paris');
