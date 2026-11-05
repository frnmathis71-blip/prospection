<?php

use App\Enums\DataSource;
use App\Enums\ProspectStatus;
use App\Jobs\AnalyzeWebsite;
use App\Jobs\EnrichCompany;
use App\Jobs\RunCompanySearch;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Search;
use App\Models\SuppressionEntry;
use App\Models\Website;
use App\Services\DnsResolver;
use App\Services\DomainGuesser;
use App\Services\OverpassPause;
use App\Services\SearchCriteria;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

const OVERPASS_URL = 'overpass-api.de/*';

beforeEach(function () {
    Sleep::fake();
    Queue::fake([AnalyzeWebsite::class]); // l'analyse du site (M5) est testée à part

    // DNS simulé : seuls les domaines listés existent.
    $this->dns = new class extends DnsResolver
    {
        public array $existing = [];

        public function hasAddress(string $domain): bool
        {
            return in_array($domain, $this->existing, true);
        }
    };
    $this->app->instance(DnsResolver::class, $this->dns);

    $this->company = Company::factory()->create([
        'name' => 'Plomberie Martin',
        'postal_code' => '71100',
        'city' => 'Chalon-sur-Saône',
        'latitude' => 46.780764,
        'longitude' => 4.853947,
        'phone' => null,
        'siren' => '123456789',
    ]);
});

function overpass(array $elements): array
{
    return [OVERPASS_URL => Http::response(['elements' => $elements])];
}

function enrich(Company $company): EnrichCompany
{
    $job = (new EnrichCompany($company))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('complète l’entreprise avec OpenStreetMap', function () {
    Http::fake(overpass([
        ['type' => 'node', 'tags' => ['name' => 'Boulangerie du Coin']],
        ['type' => 'node', 'tags' => [
            'name' => 'Plomberie Martin',
            'craft' => 'plumber',
            'phone' => '+33 3 85 12 34 56',
            'website' => 'https://www.plomberie-martin.example',
            'email' => 'contact@plomberie-martin.example',
            'opening_hours' => 'Mo-Fr 08:00-18:00',
            'contact:facebook' => 'https://www.facebook.com/plomberiemartin',
        ]],
    ]));

    enrich($this->company);
    $company = $this->company->fresh();

    expect($company->phone)->toBe('0385123456')
        ->and($company->website->url)->toBe('https://www.plomberie-martin.example')
        ->and($company->contacts->sole())
        ->email->toBe('contact@plomberie-martin.example')
        ->source->toBe(DataSource::OpenStreetMap)
        ->collected_at->not->toBeNull()
        ->and($company->osm_signals)->toMatchArray(['osm_name' => 'Plomberie Martin', 'opening_hours' => true, 'social' => true])
        ->and($company->enriched_at)->not->toBeNull()
        ->and($company->activities->pluck('content')->implode(' '))->toContain('© contributeurs OpenStreetMap');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'overpass')
        && str_contains($request['data'], 'around:150,46.780764,4.853947'));
});

it('n’écrase pas les données existantes', function () {
    $this->company->update(['phone' => '0380000000']);
    Website::factory()->for($this->company)->create(['url' => 'https://site-existant.example']);
    Http::fake(overpass([['tags' => ['name' => 'Plomberie Martin', 'phone' => '0385123456', 'website' => 'https://autre.example']]]));

    enrich($this->company);

    expect($this->company->fresh())
        ->phone->toBe('0380000000')
        ->website->url->toBe('https://site-existant.example');
});

it('ignore un lieu OpenStreetMap qui ne correspond pas au nom', function () {
    Http::fake(overpass([['tags' => ['name' => 'Plomberie Durand', 'phone' => '0385999999']]]));

    enrich($this->company);

    expect($this->company->fresh()->phone)->toBeNull()
        ->and($this->company->fresh()->osm_signals)->toBeNull();
});

it('marque « Ne pas contacter » si l’email OpenStreetMap est sur la liste d’opposition', function () {
    SuppressionEntry::factory()->create(['email' => 'contact@plomberie-martin.example']);
    Http::fake(overpass([['tags' => ['name' => 'Plomberie Martin', 'email' => 'Contact@Plomberie-Martin.example']]]));

    enrich($this->company);

    expect(Contact::count())->toBe(1)
        ->and($this->company->fresh()->status)->toBe(ProspectStatus::DoNotContact);
});

it('trouve un site non déclaré par devinette de domaine', function () {
    $this->dns->existing = ['plomberie-martin.com'];
    Http::fake(overpass([]) + [
        'https://plomberie-martin.com/robots.txt' => Http::response("User-agent: *\nDisallow: /admin/"),
        'https://plomberie-martin.com/' => Http::response('<html><title>Plomberie Martin</title><body>Votre plombier à Chalon-sur-Saône (71100)</body></html>'),
    ]);

    enrich($this->company);

    expect($this->company->fresh()->website->url)->toBe('https://plomberie-martin.com/')
        ->and($this->company->fresh()->activities->pluck('content')->implode(' '))->toContain('devinette de domaine');
});

it('ne retient pas un domaine homonyme qui ne cite pas la ville', function () {
    $this->dns->existing = ['plomberie-martin.fr'];
    Http::fake(overpass([]) + [
        'https://plomberie-martin.fr/robots.txt' => Http::response('', 404),
        'https://plomberie-martin.fr/' => Http::response('<html><body>Plomberie Martin, votre plombier à Lille</body></html>'),
    ]);

    enrich($this->company);

    expect($this->company->fresh()->website)->toBeNull();
});

it('ne visite pas un site dont le robots.txt l’interdit', function () {
    $this->dns->existing = ['plomberie-martin.fr'];
    Http::fake(overpass([]) + [
        'https://plomberie-martin.fr/robots.txt' => Http::response("User-agent: *\nDisallow: /"),
        'https://plomberie-martin.fr/' => Http::response('<html>Plomberie Martin 71100</html>'),
    ]);

    enrich($this->company);

    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://plomberie-martin.fr/');
    expect($this->company->fresh()->website)->toBeNull();
});

it('ne devine rien pour un nom uniquement générique', function () {
    $this->company->update(['name' => 'SARL Boulangerie']);
    Http::fake(overpass([]));

    enrich($this->company);

    Http::assertNothingSent(); // nom trop générique : ni OpenStreetMap ni devinette
});

it('remet le job en file si Overpass est saturé, sans planter ni réessayer aussitôt', function () {
    Http::fake([OVERPASS_URL => Http::response('This Overpass API server is currently at capacity.', 502)]);

    enrich($this->company)->assertReleased();

    Http::assertSentCount(1); // aucune nouvelle tentative immédiate
    expect($this->company->fresh()->enriched_at)->toBeNull()
        ->and(OverpassPause::isActive())->toBeTrue();
});

it('met OpenStreetMap en pause pour tous les jobs quand le serveur est saturé', function () {
    Http::fake([OVERPASS_URL => Http::response('at capacity', 502)]);
    $other = Company::factory()->create(['name' => 'Menuiserie Lambert', 'latitude' => 47.3, 'longitude' => 5.0]);

    enrich($this->company)->assertReleased();
    $job = enrich($other);

    Http::assertSentCount(1); // le second job n'interroge pas le serveur saturé
    $job->assertReleased();
});

it('double la pause à chaque saturation, jusqu’à une heure, puis la remet à zéro après un succès', function () {
    expect(OverpassPause::start())->toBe(60)
        ->and(OverpassPause::start())->toBe(120)
        ->and(OverpassPause::start())->toBe(240);

    foreach (range(1, 10) as $i) {
        OverpassPause::start();
    }

    expect(OverpassPause::start())->toBe(OverpassPause::MAX_SECONDS);

    $this->travel(2)->hours();
    Http::fake(overpass([]));
    enrich($this->company);

    expect(OverpassPause::isActive())->toBeFalse()
        ->and(OverpassPause::start())->toBe(60);
});

it('respecte un Retry-After plus long que la pause prévue', function () {
    Http::fake([OVERPASS_URL => Http::response('rate_limited', 429, ['Retry-After' => '600'])]);

    enrich($this->company)->assertReleased();

    expect(OverpassPause::until()->diffInSeconds(now(), absolute: true))->toBeGreaterThanOrEqual(599);
});

it('place les enrichissements dans une file dédiée, moins prioritaire', function () {
    expect((new EnrichCompany($this->company))->queue)->toBe('enrichment')
        ->and(config('horizon.defaults.supervisor-1.queue'))->toBe(['default', 'enrichment'])
        ->and(config('horizon.defaults.supervisor-1.balance'))->toBeFalse();
});

it('laisse à une recherche le temps de finir avant toute relance de la file', function () {
    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan((new RunCompanySearch(Search::factory()->make()))->timeout)
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan(900);
});

it('limite le débit des enrichissements', function () {
    expect((new EnrichCompany($this->company))->middleware())
        ->toHaveCount(1)
        ->{0}->toBeInstanceOf(RateLimited::class);
});

it('propose des domaines candidats à partir du nom', function () {
    expect(DomainGuesser::candidates('SARL Plomberie Martin'))->toBe([
        'plomberiemartin.fr', 'plomberie-martin.fr', 'plomberiemartin.com', 'plomberie-martin.com',
    ])->and(DomainGuesser::candidates('Boulangerie'))->toBe([]);
});

it('garde les articles dans les domaines candidats d’un domaine viticole', function () {
    expect(DomainGuesser::candidates('EARL Domaine des Grands Prés'))->toBe([
        'domainedesgrandspres.fr', 'domaine-des-grands-pres.fr', 'domaine-grands-pres.fr', 'domainegrandspres.fr',
        'domainedesgrandspres.com', 'domaine-des-grands-pres.com', 'domaine-grands-pres.com', 'domainegrandspres.com',
    ]);
});

it('propose les presets viticulture et fermes dans les recherches', function () {
    $criteria = SearchCriteria::fromArray(['departments' => ['71'], 'sectors' => ['viticulture', 'fermes']]);

    expect(config('prospection.sectors.viticulture.label'))->toBe('Viticulture et vins')
        ->and(config('prospection.sectors.fermes.label'))->toBe('Fermes et producteurs')
        ->and($criteria->allNafCodes())->toContain('01.21Z', '11.02B', '11.02A', '01.42Z', '01.50Z', '10.51C')
        ->and($criteria->toQuery()['activite_principale'])->toStartWith('01.21Z,11.02A,11.02B');
});
