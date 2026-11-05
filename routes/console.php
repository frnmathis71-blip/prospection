<?php

use App\Enums\ContactVerification;
use App\Enums\ProspectStatus;
use App\Jobs\AnalyzeWebsite;
use App\Jobs\FindContactEmails;
use App\Jobs\QueueHealthCheck;
use App\Jobs\RecalculateScores;
use App\Jobs\RunCompanySearch;
use App\Jobs\VerifyContact;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Search;
use App\Services\EmailFinder;
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

Artisan::command('app:analyze-websites {--all : Réanalyser aussi les sites déjà analysés}', function () {
    $query = Company::query()
        ->when(! $this->option('all'), fn ($query) => $query->where(fn ($query) => $query
            ->doesntHave('website')
            ->orWhereHas('website', fn ($query) => $query->whereNull('analyzed_at'))));

    $count = 0;
    $query->select('id')->chunkById(500, function ($companies) use (&$count) {
        $companies->each(fn (Company $company) => AnalyzeWebsite::dispatch($company));
        $count += $companies->count();
    });

    $this->info("{$count} analyse(s) de site mise(s) en file (file « enrichment », débit limité).");
})->purpose('Analyse les sites des entreprises (M5)');

Artisan::command('app:recalculate-scores', function () {
    RecalculateScores::dispatchSync();

    $this->info('Scores recalculés pour '.Company::count().' entreprise(s).');
})->purpose('Recalcule le score de priorité de toutes les entreprises (M6)');

Artisan::command('app:find-emails {--all : Explorer aussi les sites déjà explorés}', function () {
    $query = Company::query()
        ->where('is_active', true)
        ->whereNotIn('status', ProspectStatus::BLOCKING)
        ->whereHas('website', fn ($query) => $query->whereIn('state', EmailFinder::CRAWLABLE_STATES))
        ->when(! $this->option('all'), fn ($query) => $query->whereNull('emails_searched_at'));

    $count = 0;
    $query->select('id')->chunkById(500, function ($companies) use (&$count) {
        $companies->each(fn (Company $company) => FindContactEmails::dispatch($company));
        $count += $companies->count();
    });

    $this->info("{$count} recherche(s) d'emails mise(s) en file (file « enrichment », débit limité).");
})->purpose('Cherche les emails publiés sur les sites en ligne (M7)');

Artisan::command('app:verify-emails', function () {
    $count = 0;
    Contact::query()->where('verification', ContactVerification::Pending)->chunkById(500, function ($contacts) use (&$count) {
        $contacts->each(fn (Contact $contact) => VerifyContact::dispatch($contact));
        $count += $contacts->count();
    });

    $this->info("{$count} vérification(s) d'email mise(s) en file.");
})->purpose('Vérifie (syntaxe + MX) les emails encore « À vérifier » (M7)');

// Recherches récurrentes (mission M4) : « Plombiers 71 — chaque lundi ».
Schedule::command('app:run-recurring-searches weekly')->weeklyOn(1, '07:00')->timezone('Europe/Paris');
Schedule::command('app:run-recurring-searches monthly')->monthlyOn(1, '07:00')->timezone('Europe/Paris');
