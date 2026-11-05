<?php

use App\Enums\ContactType;
use App\Enums\DataSource;
use App\Enums\ProspectStatus;
use App\Enums\WebsiteState;
use App\Filament\Pages\ScoringSettings;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Jobs\AnalyzeWebsite;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\CompanyScorer;
use App\Services\ContactRecorder;
use App\Services\WebsiteAnalyzer;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-11-05 10:00:00');
});

/** Entreprise type : plombier installé depuis 5 ans, 1-2 salariés, téléphone et email générique. */
function plumber(array $attributes = []): Company
{
    $company = Company::factory()->create($attributes + [
        'naf_code' => '43.22A',
        'founded_on' => '2021-06-01',
        'headcount_range' => '01',
        'phone' => '0385123456',
        'osm_signals' => null,
        'status' => ProspectStatus::New,
    ]);
    Contact::factory()->for($company)->create(['email' => 'contact@plombier-'.$company->id.'.example', 'type' => ContactType::Generic]);

    return $company;
}

function scoreOf(Company $company): int
{
    return app(CompanyScorer::class)->refresh($company->fresh())->score;
}

describe('profils types', function () {
    it('donne un score élevé à une entreprise de 5 ans, sans site, joignable par téléphone', function () {
        $company = plumber();
        Website::factory()->none()->for($company)->create();

        expect(scoreOf($company))->toBe(92); // 94 points sur 102
    });

    it('donne un score élevé à un site obsolète (pas de HTTPS, pas mobile, © ancien)', function () {
        $company = plumber();
        Website::factory()->for($company)->create([
            'state' => WebsiteState::Outdated,
            'problems' => ['no_https', 'not_responsive', 'old_copyright'],
        ]);

        expect(scoreOf($company))->toBe(87);
    });

    it('donne un score bas à une entreprise au site récent et sans défaut', function () {
        $company = plumber();
        Website::factory()->for($company)->create(['state' => WebsiteState::Active, 'problems' => []]);

        expect(scoreOf($company))->toBe(33)->toBeLessThan(40);
    });

    it('classe les profils dans l’ordre attendu', function () {
        $noSite = plumber();
        Website::factory()->none()->for($noSite)->create();

        $socialOnly = plumber();
        Website::factory()->for($socialOnly)->create(['state' => WebsiteState::SocialOnly, 'problems' => []]);

        $outdatedUnreachable = Company::factory()->create(['phone' => null, 'founded_on' => '2021-01-01', 'headcount_range' => '01', 'naf_code' => '43.22A']);
        Website::factory()->for($outdatedUnreachable)->create(['state' => WebsiteState::Outdated, 'problems' => ['old_copyright']]);

        $recent = plumber();
        Website::factory()->for($recent)->create(['state' => WebsiteState::Active, 'problems' => []]);

        expect([scoreOf($noSite), scoreOf($socialOnly), scoreOf($outdatedUnreachable), scoreOf($recent)])
            ->sequence(
                fn ($score) => $score->toBeGreaterThan(scoreOf($socialOnly)),
                fn ($score) => $score->toBeGreaterThan(scoreOf($outdatedUnreachable)),
                fn ($score) => $score->toBeGreaterThan(scoreOf($recent)),
                fn ($score) => $score->toBeLessThan(40),
            );
    });

    it('valorise une entreprise créée il y a moins de 6 mois (cible « création de site »)', function () {
        $new = plumber(['founded_on' => '2026-08-01']);
        $old = plumber(['founded_on' => '2025-06-01']); // 17 mois : ni récente ni installée

        expect(scoreOf($new))->toBeGreaterThan(scoreOf($old));
    });

    it('ne compte pas un email de la liste d’opposition ni un email invalide', function () {
        $company = Company::factory()->create(['phone' => null, 'naf_code' => null, 'founded_on' => null, 'headcount_range' => null]);
        Contact::factory()->for($company)->create(['email' => 'contact@invalide.example', 'verification' => 'invalid']);

        expect(scoreOf($company))->toBe(20); // site pas encore analysé uniquement : 20 / 102
    });

    it('explique le score ligne par ligne', function () {
        $company = plumber();
        Website::factory()->none()->for($company)->create();

        $details = app(CompanyScorer::class)->refresh($company->fresh())->score_details;

        expect(collect($details)->pluck('points', 'label')->all())->toBe([
            'Aucun site' => 60,
            'Téléphone connu' => 8,
            'Email professionnel' => 8,
            'Installée depuis 5 ans' => 8,
            '1 à 9 salariés' => 6,
            'Secteur : Artisans du bâtiment' => 4,
        ]);
    });

    it('reste entre 0 et 100 quels que soient les poids', function () {
        Setting::put(CompanyScorer::SETTING_KEY, array_fill_keys(array_keys(config('prospection.scoring')), 100));
        $company = plumber(['osm_signals' => ['opening_hours' => true]]);
        Website::factory()->for($company)->create(['state' => WebsiteState::Outdated, 'problems' => ['no_https', 'not_responsive', 'old_copyright', 'no_contact']]);

        expect(scoreOf($company))->toBeLessThanOrEqual(100)->toBeGreaterThanOrEqual(0);

        Setting::put(CompanyScorer::SETTING_KEY, array_fill_keys(array_keys(config('prospection.scoring')), 0));
        expect(scoreOf($company))->toBe(0);
    });
});

describe('poids réglables', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->withAppAuthentication()->create());
    });

    it('recalcule tous les scores quand un poids change', function () {
        $noSite = plumber();
        Website::factory()->none()->for($noSite)->create();
        $before = scoreOf($noSite);

        Livewire::test(ScoringSettings::class)
            ->assertFormSet(['website_none' => 60, 'phone' => 8])
            ->fillForm(['website_none' => 10])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Poids enregistrés');

        expect(Setting::get(CompanyScorer::SETTING_KEY)['website_none'])->toBe(10)
            ->and($noSite->fresh()->score)->toBeLessThan($before)
            ->and(collect($noSite->fresh()->score_details)->firstWhere('label', 'Aucun site')['points'])->toBe(10);
    });

    it('refuse un poids négatif ou supérieur à 100', function () {
        Livewire::test(ScoringSettings::class)
            ->fillForm(['phone' => -5, 'email_pro' => 150])
            ->call('save')
            ->assertHasFormErrors(['phone', 'email_pro']);
    });

    it('revient aux poids par défaut', function () {
        Setting::put(CompanyScorer::SETTING_KEY, ['website_none' => 1]);

        Livewire::test(ScoringSettings::class)->call('resetToDefaults')->assertFormSet(['website_none' => 60]);

        expect(Setting::get(CompanyScorer::SETTING_KEY))->toBeNull();
    });
});

describe('liste à traiter', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->withAppAuthentication()->create());
    });

    it('n’affiche jamais une entreprise « Ne pas contacter », même avec le meilleur score', function () {
        $todo = Company::factory()->create(['score' => 50, 'status' => ProspectStatus::New]);
        $blocked = collect([ProspectStatus::DoNotContact, ProspectStatus::Unsubscribed, ProspectStatus::Bounced])
            ->map(fn ($status) => Company::factory()->create(['score' => 100, 'status' => $status]));
        $closed = Company::factory()->create(['score' => 100, 'is_active' => false]);
        $contacted = Company::factory()->create(['score' => 100, 'status' => ProspectStatus::Contacted]);

        Livewire::test(ListCompanies::class)
            ->assertSet('activeTab', 'a_traiter')
            ->assertCanSeeTableRecords([$todo])
            ->assertCanNotSeeTableRecords([...$blocked, $closed, $contacted]);

        expect(Company::query()->toProcess()->pluck('id')->all())->toBe([$todo->id]);
    });

    it('trie la liste à traiter du meilleur au moins bon score', function () {
        $low = Company::factory()->create(['score' => 20]);
        $high = Company::factory()->create(['score' => 95]);
        $middle = Company::factory()->create(['score' => 60]);

        Livewire::test(ListCompanies::class)->assertCanSeeTableRecords([$high, $middle, $low], inOrder: true);
    });

    it('regroupe les entreprises bloquées dans leur propre onglet', function () {
        $blocked = Company::factory()->create(['status' => ProspectStatus::DoNotContact]);

        Livewire::test(ListCompanies::class)
            ->set('activeTab', 'bloquees')
            ->assertCanSeeTableRecords([$blocked]);
    });

    it('affiche le détail du score sur la fiche', function () {
        $company = plumber();
        Website::factory()->none()->for($company)->create();
        scoreOf($company);

        Livewire::test(ViewCompany::class, ['record' => $company->getKey()])
            ->assertSee('Pourquoi ce score ?')
            ->assertSee('+60 pts : Aucun site')
            ->assertSee('92/100');
    });
});

describe('recalcul automatique', function () {
    it('recalcule le score après l’analyse du site', function () {
        $company = plumber(['score' => null]);
        Website::factory()->for($company)->create(['url' => 'https://plombier.example/', 'analyzed_at' => null]);
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            'https://plombier.example/' => Http::response('<html><body>© 2010 Plombier</body></html>'),
            'www.googleapis.com/*' => Http::response([], 500),
        ]);

        (new AnalyzeWebsite($company))->handle(app(WebsiteAnalyzer::class), app(CompanyScorer::class));

        expect($company->fresh())
            ->score->toBeGreaterThan(70)
            ->scored_at->not->toBeNull()
            ->score_details->not->toBeEmpty();
    });

    it('recalcule le score quand un email est trouvé', function () {
        $company = Company::factory()->create(['score' => null]);

        ContactRecorder::record($company, 'contact@exemple.example', DataSource::Manual);

        expect(collect($company->fresh()->score_details)->pluck('label'))->toContain('Email professionnel');
    });

    it('recalcule tous les scores par la commande', function () {
        $companies = Company::factory()->count(3)->create(['score' => null]);

        $this->artisan('app:recalculate-scores')->assertSuccessful();

        expect(Company::whereNull('scored_at')->count())->toBe(0);
    });
});

it('ne présume pas « aucun site » tant que le site n’a pas été analysé', function () {
    $notAnalyzed = plumber();
    $noSite = plumber();
    Website::factory()->none()->for($noSite)->create();

    expect(collect(app(CompanyScorer::class)->refresh($notAnalyzed->fresh())->score_details)->pluck('label'))
        ->toContain('Site pas encore analysé')
        ->not->toContain('Aucun site')
        ->and(scoreOf($noSite))->toBeGreaterThan(scoreOf($notAnalyzed));
});
