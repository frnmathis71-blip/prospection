<?php

use App\Enums\CampaignStatus;
use App\Enums\ContactVerification;
use App\Enums\ProspectStatus;
use App\Jobs\AnalyzeWebsite;
use App\Jobs\FindContactEmails;
use App\Jobs\QueueHealthCheck;
use App\Jobs\ReadInbox;
use App\Jobs\RecalculateScores;
use App\Jobs\RunCompanySearch;
use App\Jobs\SendNextEmail;
use App\Jobs\VerifyContact;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Search;
use App\Services\CircuitBreaker;
use App\Services\EmailFinder;
use App\Services\Inbox\Mailbox;
use App\Services\PersonalDataExporter;
use App\Services\ProductionCheck;
use App\Services\ProspectPurger;
use App\Support\BusinessCalendar;
use App\Support\Periodic;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
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

// Envoi (mission M9) : chaque minute, au plus un email part, si une campagne est en cours et que
// l'heure est dans un créneau d'envoi (jours ouvrés, 8 h-11 h et 14 h-17 h). Tout passe par la file.
Schedule::job(new SendNextEmail)
    ->everyMinute()
    ->when(fn () => BusinessCalendar::isSendingTime() && Campaign::query()->where('status', CampaignStatus::Active)->exists());

Artisan::command('app:read-inbox', function () {
    if (! app(Mailbox::class)->isConfigured()) {
        $this->warn('IMAP non configuré : renseigner IMAP_HOST, IMAP_USERNAME et IMAP_PASSWORD dans le .env.');

        return;
    }

    $counts = app()->call([new ReadInbox, 'handle']);
    $this->info($counts === [] ? 'Aucun nouveau message.' : collect($counts)->map(fn ($n, $kind) => "{$kind} : {$n}")->implode(', '));
})->purpose('Lit la boîte d’envoi : réponses, absences, rebonds (M10)');

// Boîte d'envoi (M10) : réponses, absences et rebonds, toutes les 5 minutes.
Schedule::job(new ReadInbox)->everyFiveMinutes()->when(fn () => app(Mailbox::class)->isConfigured());

// Coupe-circuit (M11) : contrôle horaire des rebonds et désinscriptions sur 7 jours.
Schedule::call(fn () => app(CircuitBreaker::class)->check())->everyMinute()->when(Periodic::hourly('coupe-circuit'))->name('coupe-circuit');

Artisan::command('app:purge-prospects {--dry-run : Afficher le nombre sans rien supprimer}', function (ProspectPurger $purger) {
    if ($this->option('dry-run')) {
        $this->info($purger->candidates()->count().' prospect(s) seraient supprimés (sans réponse depuis plus de 3 ans).');

        return;
    }

    $this->info($purger->purge().' prospect(s) supprimés. La liste d’opposition est conservée.');
})->purpose('Purge RGPD : prospects sans réponse depuis plus de 3 ans (M12)');

Artisan::command('app:export-person {email : Adresse de la personne}', function (string $email, PersonalDataExporter $exporter) {
    $path = 'exports/rgpd-'.Str::slug($email).'-'.now()->format('Ymd-His').'.json';
    Storage::disk('local')->put($path, json_encode($exporter->export($email), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $this->info('Export enregistré : '.Storage::disk('local')->path($path));
})->purpose('Exporte toutes les données d’une personne, avec leur source (droit d’accès RGPD, M12)');

Artisan::command('app:production-check', function (ProductionCheck $check) {
    $problems = $check->problems();

    if ($problems === []) {
        $this->info('Configuration de production : tout est en ordre.');

        return 0;
    }

    $this->error(count($problems).' point(s) à corriger :');
    foreach ($problems as $problem) {
        $this->line(' - '.$problem);
    }

    return 1;
})->purpose('Vérifie la configuration avant la mise en production (M12)');

// RGPD (M12) : purge des prospects sans réponse depuis plus de 3 ans, chaque nuit.
Schedule::command('app:purge-prospects')->everyMinute()->when(Periodic::daily('purge', '03:30'));

// Sauvegardes (M12) : base de données chaque nuit, nettoyage des anciennes, contrôle de fraîcheur.
Schedule::command('backup:clean')->everyMinute()->when(Periodic::daily('backup-clean', '01:00'));
Schedule::command('backup:run --only-db')->everyMinute()->when(Periodic::daily('backup-run', '01:30'));
Schedule::command('backup:monitor')->everyMinute()->when(Periodic::daily('backup-monitor', '09:00'));

// Recherches récurrentes (mission M4) : « Plombiers 71 — chaque lundi ».
// Tâches à heure fixe (heure de Paris) : « une fois par période », au premier passage après l'heure
// prévue (App\Support\Periodic), pour qu'aucune ne saute sur un hébergement mutualisé.
Schedule::command('app:run-recurring-searches weekly')->everyMinute()->when(Periodic::weekly('searches-weekly', 1, '07:00'));
Schedule::command('app:run-recurring-searches monthly')->everyMinute()->when(Periodic::monthly('searches-monthly', 1, '07:00'));
