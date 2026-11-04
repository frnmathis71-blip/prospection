<?php

use App\Filament\Imports\CompanyImporter;
use App\Filament\Widgets\ImportsWidget;
use App\Filament\Widgets\QueueStatusWidget;
use App\Jobs\QueueHealthCheck;
use App\Models\User;
use App\Support\WorkerHeartbeat;
use Filament\Actions\Imports\Models\Import;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->withAppAuthentication()->create();
    $this->actingAs($this->user);
    config(['queue.default' => 'database']);
});

function makeImport(array $attributes = []): Import
{
    return Import::create(array_merge([
        'user_id' => User::first()->id,
        'file_name' => 'prospects.csv',
        'file_path' => 'imports/prospects.csv',
        'importer' => CompanyImporter::class,
        'total_rows' => 10,
        'processed_rows' => 0,
        'successful_rows' => 0,
    ], $attributes));
}

it('affiche le suivi de la file et des imports sur le tableau de bord', function () {
    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSeeLivewire(QueueStatusWidget::class)
        ->assertSeeLivewire(ImportsWidget::class);
});

it('montre l’état et la progression de chaque import', function () {
    $waiting = makeImport(['file_name' => 'attente.csv']);
    $running = makeImport(['file_name' => 'en-cours.csv', 'processed_rows' => 4, 'successful_rows' => 4]);
    $done = makeImport(['file_name' => 'fini.csv', 'processed_rows' => 10, 'successful_rows' => 10, 'completed_at' => now()]);
    $withErrors = makeImport(['file_name' => 'erreurs.csv', 'processed_rows' => 10, 'successful_rows' => 8, 'completed_at' => now()]);
    $withErrors->failedRows()->createMany([
        ['data' => ['nom' => ''], 'validation_error' => 'Le champ nom est obligatoire.'],
        ['data' => ['nom' => 'X'], 'validation_error' => 'Le champ email doit être une adresse email valide.'],
    ]);

    Livewire::test(ImportsWidget::class)
        ->assertCanSeeTableRecords([$waiting, $running, $done, $withErrors])
        ->assertTableColumnStateSet('status', 'En attente', $waiting)
        ->assertTableColumnStateSet('status', 'En cours', $running)
        ->assertTableColumnStateSet('progress', '40 %', $running)
        ->assertTableColumnStateSet('status', 'Terminé', $done)
        ->assertSee('Le '.now()->format('d/m/Y').' à') // date de fin affichée (timestamp Filament)
        ->assertSee('2 en erreur')
        ->assertTableColumnStateSet('status', 'Terminé avec erreurs', $withErrors)
        ->assertTableColumnStateSet('failed_rows_count', 2, $withErrors)
        ->assertTableActionVisible('failedRows', $withErrors)
        ->assertTableActionHidden('failedRows', $done);
});

it('donne un lien de rapport d’erreurs qui fonctionne', function () {
    $import = makeImport(['processed_rows' => 10, 'successful_rows' => 9, 'completed_at' => now()]);
    $import->failedRows()->create(['data' => ['name' => ''], 'validation_error' => 'Le champ nom est obligatoire.']);

    $url = Livewire::test(ImportsWidget::class)
        ->instance()
        ->getTable()
        ->getAction('failedRows')
        ->record($import->loadCount('failedRows'))
        ->getUrl();

    $response = $this->get($url)->assertOk()->assertDownload();

    expect($response->streamedContent())->toContain('Le champ nom est obligatoire.');
});

it('indique que tout va bien quand la file est vide et le worker actif', function () {
    WorkerHeartbeat::beat();

    Livewire::test(QueueStatusWidget::class)
        ->assertSee('Rien à traiter')
        ->assertSee('Actif')
        ->assertSee('Aucun échec')
        ->assertDontSee('Arrêté');
});

it('signale un worker arrêté quand une tâche attend sans signal de vie', function () {
    QueueHealthCheck::dispatch('jeton');
    $this->travel(5)->minutes();

    Livewire::test(QueueStatusWidget::class)
        ->assertSee('Arrêté')
        ->assertSee('php artisan queue:work');
});

it('ne signale pas d’arrêt quand le worker est actif mais que des tâches attendent leur tour', function () {
    QueueHealthCheck::dispatch('jeton');
    $this->travel(5)->minutes();
    WorkerHeartbeat::beat(); // le worker tourne (débit limité, job long…)

    Livewire::test(QueueStatusWidget::class)
        ->assertSee('Actif')
        ->assertSee('Traitement en cours')
        ->assertDontSee('Arrêté');
});

it('considère le worker arrêté sans signal depuis plus de 90 secondes', function () {
    WorkerHeartbeat::beat();
    $this->travel(2)->minutes();

    expect(WorkerHeartbeat::isAlive())->toBeFalse();
});

it('fait battre le signal de vie à chaque tour du worker', function () {
    Cache::forget(WorkerHeartbeat::CACHE_KEY);
    QueueHealthCheck::dispatch('jeton');

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

    expect(WorkerHeartbeat::isAlive())->toBeTrue();
});

it('compte les tâches échouées', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Exception de test',
        'failed_at' => now(),
    ]);

    Livewire::test(QueueStatusWidget::class)->assertSee('queue:failed');
});
