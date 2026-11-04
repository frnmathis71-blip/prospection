<?php

use App\Enums\DataSource;
use App\Enums\SearchStatus;
use App\Jobs\EnrichCompany;
use App\Jobs\RunCompanySearch;
use App\Models\Company;
use App\Models\Search;
use App\Services\SearchCriteria;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

const API_URL = 'recherche-entreprises.api.gouv.fr/search*';
const BODACC_URL = 'bodacc-datadila.opendatasoft.com/*';

beforeEach(function () {
    Sleep::fake();
    Queue::fake([EnrichCompany::class]);
});

/** Résultat d'API (entreprise fictive). */
function apiCompany(string $siren, array $overrides = []): array
{
    return array_replace_recursive([
        'siren' => $siren,
        'nom_complet' => "PLOMBERIE TEST {$siren}",
        'nom_raison_sociale' => "PLOMBERIE TEST {$siren}",
        'activite_principale' => '43.22A',
        'tranche_effectif_salarie' => '01',
        'date_creation' => '2015-03-01',
        'etat_administratif' => 'A',
        'siege' => [
            'adresse' => '12 RUE DE LA GARE 71100 CHALON-SUR-SAONE',
            'code_postal' => '71100',
            'libelle_commune' => 'CHALON-SUR-SAONE',
            'departement' => '71',
            'latitude' => '46.780764',
            'longitude' => '4.853947',
            'etat_administratif' => 'A',
        ],
        'matching_etablissements' => [],
        'complements' => ['est_entrepreneur_individuel' => false],
    ], $overrides);
}

function apiPage(array $results, int $page = 1, int $totalPages = 1, ?int $total = null): array
{
    return [
        'results' => $results,
        'total_results' => $total ?? count($results),
        'page' => $page,
        'per_page' => 25,
        'total_pages' => $totalPages,
    ];
}

function bodaccNone(): void
{
    Http::fake([BODACC_URL => Http::response(['total_count' => 0, 'results' => []])]);
}

function runSearch(Search $search): RunCompanySearch
{
    $job = (new RunCompanySearch($search))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

function plumbersSearch(array $criteria = []): Search
{
    return Search::factory()->create([
        'name' => 'Plombiers 71',
        'criteria' => $criteria + ['departments' => ['71'], 'naf_codes' => ['43.22A']],
    ]);
}

it('traduit les filtres en paramètres de requête', function () {
    $criteria = SearchCriteria::fromArray([
        'departments' => ['71', '21'],
        'postal_codes' => ['71100'],
        'commune_codes' => ['71076'],
        'sectors' => ['beaute'],
        'naf_codes' => ['43.22a'],
        'headcount_ranges' => ['01', '02'],
        'max_age_months' => 6,
    ]);

    expect($criteria->toQuery(3))->toBe([
        'departement' => '71,21',
        'code_postal' => '71100',
        'code_commune' => '71076',
        'activite_principale' => '96.02A,96.02B,96.04Z,43.22A',
        'tranche_effectif_salarie' => '01,02',
        'etat_administratif' => 'A',
        'minimal' => 'true',
        'include' => 'siege,complements,matching_etablissements',
        'per_page' => 25,
        'page' => 3,
    ]);
});

it('envoie ces paramètres à l’API avec un User-Agent identifiant l’outil', function () {
    bodaccNone();
    Http::fake([API_URL => Http::response(apiPage([]))]);

    runSearch(plumbersSearch(['headcount_ranges' => ['01']]));

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://recherche-entreprises.api.gouv.fr/search?')
        && $request['departement'] === '71'
        && $request['activite_principale'] === '43.22A'
        && $request['tranche_effectif_salarie'] === '01'
        && $request['etat_administratif'] === 'A'
        && (string) $request['page'] === '1'
        && str_starts_with($request->header('User-Agent')[0], 'CodenyrProspection/'));
});

it('récupère toutes les pages de résultats', function () {
    bodaccNone();
    Http::fake([API_URL => Http::sequence()
        ->push(apiPage([apiCompany('100000001'), apiCompany('100000002')], 1, 3, 6))
        ->push(apiPage([apiCompany('100000003'), apiCompany('100000004')], 2, 3, 6))
        ->push(apiPage([apiCompany('100000005'), apiCompany('100000006')], 3, 3, 6)),
    ]);

    $search = plumbersSearch();
    runSearch($search);

    Http::assertSentCount(3 + 3); // 3 pages + 3 vérifications BODACC
    foreach ([1, 2, 3] as $page) {
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'recherche-entreprises') && (string) $request['page'] === (string) $page);
    }

    expect(Company::count())->toBe(6)
        ->and($search->fresh())
        ->status->toBe(SearchStatus::Done)
        ->last_run_at->not->toBeNull()
        ->last_run_stats->toMatchArray(['found' => 6, 'received' => 6, 'created' => 6, 'pages' => 3]);

    Queue::assertPushed(EnrichCompany::class, 6);
});

it('s’arrête au nombre maximum de pages', function () {
    config(['prospection.recherche_entreprises.max_pages' => 2]);
    bodaccNone();
    Http::fake([API_URL => Http::sequence()
        ->push(apiPage([apiCompany('100000001')], 1, 50, 1250))
        ->push(apiPage([apiCompany('100000002')], 2, 50, 1250)),
    ]);

    $search = plumbersSearch();
    runSearch($search);

    expect(Company::count())->toBe(2)
        ->and($search->fresh()->last_run_stats['truncated'])->toBeTrue();
});

it('enregistre les entreprises avec leurs données et leur origine', function () {
    bodaccNone();
    Http::fake([API_URL => Http::response(apiPage([apiCompany('123456789', ['nom_raison_sociale' => 'PLOMBERIE MARTIN'])]))]);

    $search = plumbersSearch();
    runSearch($search);

    expect(Company::sole())
        ->siren->toBe('123456789')
        ->name->toBe('Plomberie Martin')
        ->naf_code->toBe('43.22A')
        ->address->toBe('12 Rue De La Gare')
        ->postal_code->toBe('71100')
        ->city->toBe('Chalon-Sur-Saone')
        ->department->toBe('71')
        ->headcount_range->toBe('01')
        ->founded_on->format('Y-m-d')->toBe('2015-03-01')
        ->source->toBe(DataSource::RechercheEntreprises)
        ->search_id->toBe($search->id);
});

it('exclut les entreprises fermées, en procédure collective ou hors ancienneté', function () {
    Http::fake([
        API_URL => Http::response(apiPage([
            apiCompany('100000001'), // gardée
            apiCompany('100000002', ['etat_administratif' => 'C']), // fermée
            apiCompany('100000003', ['siege' => ['etat_administratif' => 'F']]), // aucun établissement ouvert
            apiCompany('100000004'), // procédure collective (BODACC)
            apiCompany('100000005', ['date_creation' => '1990-01-01']), // trop ancienne pour le critère
        ])),
        BODACC_URL => Http::response(['total_count' => 1, 'results' => [['registre' => ['100000004', '100 000 004']]]]),
    ]);

    $search = plumbersSearch(['min_age_years' => 2, 'max_age_months' => 360]);
    $this->travelTo('2026-11-04');
    runSearch($search);

    expect(Company::pluck('siren')->all())->toBe(['100000001'])
        ->and($search->fresh()->last_run_stats)->toMatchArray([
            'excluded_closed' => 2,
            'excluded_procedure' => 1,
            'excluded_age' => 1,
            'created' => 1,
        ]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'bodacc')
        && str_contains($request['where'], 'familleavis = "collective"')
        && str_contains($request['where'], 'registre IN ("100000001", "100000004")'));
});

it('garde l’établissement ouvert de la zone quand le siège est fermé ou ailleurs', function () {
    bodaccNone();
    Http::fake([API_URL => Http::response(apiPage([apiCompany('100000001', [
        'siege' => ['etat_administratif' => 'F', 'code_postal' => '75001', 'departement' => '75'],
        'matching_etablissements' => [[
            'adresse' => '3 PLACE DU MARCHE 71100 CHALON-SUR-SAONE',
            'code_postal' => '71100',
            'libelle_commune' => 'CHALON-SUR-SAONE',
            'departement' => '71',
            'latitude' => '46.78',
            'longitude' => '4.85',
            'etat_administratif' => 'A',
        ]],
    ])]))]);

    runSearch(plumbersSearch());

    expect(Company::sole())->address->toBe('3 Place Du Marche')->department->toBe('71');
});

it('retente après une limite de débit (429) sans planter', function () {
    bodaccNone();
    Http::fake([API_URL => Http::sequence()
        ->push(['message' => 'Too Many Requests'], 429, ['Retry-After' => '1'])
        ->push(apiPage([apiCompany('100000001')])),
    ]);

    $search = plumbersSearch();
    $job = runSearch($search);

    $job->assertNotReleased();
    expect(Company::count())->toBe(1)
        ->and($search->fresh()->status)->toBe(SearchStatus::Done);
});

it('remet le job en file plus tard si l’API reste indisponible', function () {
    Http::fake([API_URL => Http::response('Service Unavailable', 503)]);

    $search = plumbersSearch();
    $job = runSearch($search);

    $job->assertReleased(60);
    expect(Company::count())->toBe(0)
        ->and($search->fresh())
        ->status->toBe(SearchStatus::Retrying)
        ->last_error->toContain('HTTP 503');
});

it('respecte le délai Retry-After demandé par l’API', function () {
    Http::fake([API_URL => Http::response(['message' => 'Too Many Requests'], 429, ['Retry-After' => '120'])]);

    runSearch(plumbersSearch())->assertReleased(120);
});

it('remet aussi le job en file si le BODACC est indisponible', function () {
    Http::fake([
        API_URL => Http::response(apiPage([apiCompany('100000001')])),
        BODACC_URL => Http::response('Bad Gateway', 502),
    ]);

    runSearch(plumbersSearch())->assertReleased();
    expect(Company::count())->toBe(0);
});

it('n’ajoute que les nouvelles entreprises quand on relance une recherche', function () {
    bodaccNone();
    Http::fake([API_URL => Http::sequence()
        ->push(apiPage([apiCompany('100000001'), apiCompany('100000002')]))
        ->push(apiPage([apiCompany('100000001'), apiCompany('100000002'), apiCompany('100000003')])),
    ]);

    $search = plumbersSearch();
    runSearch($search);
    Company::where('siren', '100000001')->update(['name' => 'Nom corrigé à la main']);
    runSearch($search);

    expect(Company::count())->toBe(3)
        ->and(Company::where('siren', '100000001')->value('name'))->toBe('Nom corrigé à la main')
        ->and($search->fresh()->last_run_stats)->toMatchArray(['created' => 1, 'already_known' => 2]);

    Queue::assertPushed(EnrichCompany::class, 3);
});

it('complète une entreprise importée sans SIREN au lieu de la dupliquer', function () {
    bodaccNone();
    $imported = Company::factory()->create([
        'siren' => null,
        'name' => 'Plomberie Martin',
        'postal_code' => '71100',
        'naf_code' => null,
    ]);
    Http::fake([API_URL => Http::response(apiPage([apiCompany('123456789', ['nom_raison_sociale' => 'SARL PLOMBERIE MARTIN'])]))]);

    $search = plumbersSearch();
    runSearch($search);

    expect(Company::count())->toBe(1)
        ->and($imported->fresh())->siren->toBe('123456789')->naf_code->toBe('43.22A')->name->toBe('Plomberie Martin')
        ->and($search->fresh()->last_run_stats['completed'])->toBe(1);
});

it('refuse de lancer une recherche sans zone ni secteur', function () {
    Http::fake();
    $search = Search::factory()->create(['criteria' => ['headcount_ranges' => ['01']]]);

    runSearch($search);

    Http::assertNothingSent();
    expect($search->fresh()->status)->toBe(SearchStatus::Failed);
});

it('relance les recherches récurrentes via la commande planifiée', function () {
    Queue::fake();
    $weekly = Search::factory()->create(['recurrence' => 'weekly']);
    Search::factory()->create(['recurrence' => 'monthly']);
    Search::factory()->create(['recurrence' => null]);

    $this->artisan('app:run-recurring-searches weekly')->assertSuccessful();

    Queue::assertPushed(RunCompanySearch::class, 1);
    Queue::assertPushed(RunCompanySearch::class, fn (RunCompanySearch $job) => $job->search->is($weekly));
});

it('planifie les relances hebdomadaires et mensuelles', function () {
    $commands = collect(app(Schedule::class)->events())->map->command->implode("\n");

    expect($commands)
        ->toContain('app:run-recurring-searches weekly')
        ->toContain('app:run-recurring-searches monthly');
});
