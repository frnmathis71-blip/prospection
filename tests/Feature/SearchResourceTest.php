<?php

use App\Enums\SearchStatus;
use App\Filament\Resources\Searches\Pages\CreateSearch;
use App\Filament\Resources\Searches\Pages\ListSearches;
use App\Filament\Resources\Searches\Pages\ViewSearch;
use App\Filament\Widgets\SearchesWidget;
use App\Jobs\RunCompanySearch;
use App\Models\Search;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withAppAuthentication()->create());
    Queue::fake();
});

it('enregistre une recherche avec ses critères', function () {
    Livewire::test(CreateSearch::class)
        ->fillForm([
            'name' => 'Plombiers 71',
            'recurrence' => 'weekly',
            'criteria' => [
                'departments' => ['71'],
                'sectors' => ['batiment'],
                'naf_codes' => ['43.22A'],
                'headcount_ranges' => ['01', '02'],
                'max_age_months' => 6,
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $search = Search::sole();

    expect($search->name)->toBe('Plombiers 71')
        ->and($search->recurrence)->toBe('weekly')
        ->and($search->searchCriteria())
        ->departments->toBe(['71'])
        ->sectors->toBe(['batiment'])
        ->headcountRanges->toBe(['01', '02'])
        ->maxAgeMonths->toBe(6);
});

it('refuse une recherche sans zone ni secteur', function () {
    Livewire::test(CreateSearch::class)
        ->fillForm(['name' => 'Trop large', 'criteria' => ['headcount_ranges' => ['01']]])
        ->call('create')
        ->assertNotified('Choisissez au moins une zone ou un secteur');

    expect(Search::count())->toBe(0);
});

it('refuse un code NAF ou un code postal mal formé', function () {
    Livewire::test(CreateSearch::class)
        ->fillForm(['name' => 'Erreurs', 'criteria' => ['naf_codes' => ['4322'], 'postal_codes' => ['711']]])
        ->call('create')
        ->assertHasFormErrors(['criteria.naf_codes.0', 'criteria.postal_codes.0']);
});

it('lance une recherche en tâche de fond depuis la liste et depuis la fiche', function () {
    $search = Search::factory()->create();

    Livewire::test(ListSearches::class)->callTableAction('run', $search);

    Queue::assertPushed(RunCompanySearch::class, fn (RunCompanySearch $job) => $job->search->is($search));
    expect($search->fresh()->status)->toBe(SearchStatus::Queued);

    Livewire::test(ViewSearch::class, ['record' => $search->getKey()])
        ->assertActionDisabled('run'); // déjà en file : pas de double lancement
});

it('affiche le suivi des recherches sur le tableau de bord', function () {
    Search::factory()->create([
        'name' => 'Plombiers 71',
        'status' => SearchStatus::Running,
        'last_run_stats' => ['found' => 120, 'received' => 50, 'created' => 12],
    ]);

    $this->get(Dashboard::getUrl())->assertOk()->assertSeeLivewire(SearchesWidget::class);

    Livewire::test(SearchesWidget::class)
        ->assertSee('Plombiers 71')
        ->assertSee('En cours')
        ->assertSee('50 / 120 résultats parcourus');
});
