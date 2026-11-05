<?php

use App\Enums\WebsiteProblem;
use App\Enums\WebsiteState;
use App\Jobs\AnalyzeWebsite;
use App\Jobs\EnrichCompany;
use App\Models\Company;
use App\Models\Website;
use App\Services\WebsiteAnalyzer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const PAGESPEED_URL = 'www.googleapis.com/pagespeedonline/*';

beforeEach(function () {
    $this->travelTo('2026-11-05 10:00:00');
});

function websiteFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/websites/{$name}.html"));
}

function pagespeed(float $score): array
{
    return [PAGESPEED_URL => Http::response(['lighthouseResult' => ['categories' => ['performance' => ['score' => $score]]]])];
}

/**
 * Simule le réseau : les URL données, puis robots.txt absent et PageSpeed à 92/100 par défaut.
 *
 * @param  array<string, mixed>  $routes
 */
function fakeWeb(array $routes, ?float $pagespeed = 0.92): void
{
    Http::fake($routes + ($pagespeed !== null ? pagespeed($pagespeed) : []) + ['*/robots.txt' => Http::response('', 404)]);
}

function analyzeUrl(?string $url): Website
{
    $website = Website::factory()->create(['url' => $url, 'state' => WebsiteState::Unknown, 'problems' => [], 'technologies' => []]);

    return app(WebsiteAnalyzer::class)->analyze($website)->fresh();
}

function connectionError(string $message): Closure
{
    return fn () => throw new ConnectionException($message);
}

it('ne remonte aucun problème sur un site moderne', function () {
    fakeWeb(['https://plomberie-martin.example/' => Http::response(websiteFixture('modern'))]);

    $website = analyzeUrl('https://plomberie-martin.example/');

    expect($website)
        ->state->toBe(WebsiteState::Active)
        ->https->toBeTrue()
        ->responsive->toBeTrue()
        ->copyright_year->toBe(2025)
        ->pagespeed->toBe(92)
        ->problems->toBe([])
        ->technologies->toBe(['WordPress' => '6.6.2', 'jQuery' => '3.7.1'])
        ->analyzed_at->not->toBeNull();
});

it('remonte les trois problèmes d’un site en HTTP, sans viewport, avec « © 2012 »', function () {
    fakeWeb([
        'https://menuiserie-lambert.example/' => connectionError('cURL error 7: Failed to connect to port 443: Connection refused'),
        'http://menuiserie-lambert.example/' => Http::response(websiteFixture('outdated')),
    ], pagespeed: 0.31);

    $website = analyzeUrl('http://menuiserie-lambert.example/');

    expect($website->state)->toBe(WebsiteState::Outdated)
        ->and($website->https)->toBeFalse()
        ->and($website->responsive)->toBeFalse()
        ->and($website->copyright_year)->toBe(2012)
        ->and($website->problems)->toContain('no_https', 'not_responsive', 'old_copyright')
        ->and($website->problems)->toContain('outdated_cms', 'old_jquery', 'table_layout', 'slow_mobile', 'no_legal_notice', 'no_contact')
        ->and($website->technologies)->toBe(['WordPress' => '4.9.8', 'jQuery' => '1.11.3']);

    expect($website->problemLabels())->toContain(
        'Le site n’est pas sécurisé (pas de HTTPS)',
        'Le site n’est pas adapté à l’affichage sur téléphone',
        'Le site ne semble plus mis à jour (© 2012)',
        'Le site utilise une ancienne version de WordPress 4.9.8',
        'Le site utilise une bibliothèque ancienne (jQuery 1.11.3)',
        'Le site est lent sur mobile (score PageSpeed 31/100)',
    );
});

it('classe les problèmes du plus grave au moins grave', function () {
    $website = Website::factory()->make(['problems' => ['no_contact', 'old_copyright', 'no_https']]);

    expect($website->detectedProblems())->toBe([WebsiteProblem::NoHttps, WebsiteProblem::OldCopyright, WebsiteProblem::NoContact]);
});

it('repère Flash, les cadres et les vieux CMS', function () {
    fakeWeb(['https://relais.example/' => Http::response(websiteFixture('legacy-flash'))]);

    $website = analyzeUrl('https://relais.example/');

    expect($website->problems)->toContain('flash', 'frames', 'outdated_cms', 'old_copyright')
        ->and($website->copyright_year)->toBe(2011)
        ->and($website->technologies)->toHaveKeys(['Joomla', 'Flash', 'Frames']);
});

describe('sites injoignables, sans erreur', function () {
    it('classe hors ligne un domaine qui ne répond plus (DNS)', function () {
        fakeWeb([
            'https://disparu.example/' => connectionError('cURL error 6: Could not resolve host: disparu.example'),
            'http://disparu.example/' => connectionError('cURL error 6: Could not resolve host: disparu.example'),
        ]);

        expect(analyzeUrl('disparu.example'))
            ->state->toBe(WebsiteState::Offline)
            ->analysis_note->toBe('Le nom de domaine ne pointe plus vers aucun serveur.');
    });

    it('classe hors ligne un site qui dépasse le délai', function () {
        fakeWeb([
            'https://lent.example/' => connectionError('cURL error 28: Operation timed out after 15000 milliseconds'),
            'http://lent.example/' => connectionError('cURL error 28: Operation timed out after 15000 milliseconds'),
        ]);

        expect(analyzeUrl('https://lent.example/'))
            ->state->toBe(WebsiteState::Offline)
            ->analysis_note->toBe('Le site ne répond pas (délai dépassé).');
    });

    it('analyse quand même un site au certificat expiré, et le signale', function () {
        $calls = 0;
        fakeWeb(['https://expire.example/' => function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('cURL error 60: SSL certificate problem: certificate has expired');
            }

            return Http::response(websiteFixture('modern'));
        }]);

        $website = analyzeUrl('https://expire.example/');

        expect($website)
            ->state->toBe(WebsiteState::Outdated)
            ->https->toBeFalse()
            ->problems->toBe(['invalid_certificate'])
            ->and($website->problemLabels())->toBe(['Le certificat de sécurité du site est expiré ou invalide']);
    });

    it('classe hors ligne une page d’accueil en erreur', function (int $status) {
        fakeWeb([
            'https://erreur.example/' => Http::response('Erreur', $status),
            'http://erreur.example/' => Http::response('Erreur', $status),
        ]);

        expect(analyzeUrl('https://erreur.example/'))
            ->state->toBe(WebsiteState::Offline)
            ->http_status->toBe($status)
            ->analysis_note->toContain("HTTP {$status}");
    })->with([404, 500, 503]);
});

describe('classement', function () {
    it('classe une page parking', function () {
        fakeWeb(['https://menuiserie-lambert.example/' => Http::response(websiteFixture('parking'))]);

        expect(analyzeUrl('https://menuiserie-lambert.example/'))
            ->state->toBe(WebsiteState::Parking)
            ->analysis_note->toContain('domain is for sale');
    });

    it('classe un domaine redirigé vers un service de parking', function () {
        fakeWeb([
            'https://ancien-site.example/' => Http::response('', 302, ['Location' => 'https://www.sedoparking.com/ancien-site.example']),
            'https://www.sedoparking.com/*' => Http::response('<html>Parking</html>'),
        ]);

        expect(analyzeUrl('https://ancien-site.example/')->state)->toBe(WebsiteState::Parking);
    });

    it('ne prend pas pour une page parking le vrai site d’une entreprise du bâtiment', function () {
        $html = str_replace('<main>', '<main><p>Suivi de votre site en construction : chantiers de maisons individuelles.</p>'.str_repeat('<p>Réalisations, rénovation, extension de maison, isolation, charpente et couverture en Bourgogne.</p>', 40), websiteFixture('modern'));
        fakeWeb(['https://batisseur.example/' => Http::response($html)]);

        expect(analyzeUrl('https://batisseur.example/')->state)->toBe(WebsiteState::Active);
    });

    it('classe une page Facebook en « réseau social seul », sans la visiter', function () {
        Http::fake();

        expect(analyzeUrl('https://www.facebook.com/plomberiemartin')->state)->toBe(WebsiteState::SocialOnly);
        Http::assertNothingSent();
    });

    it('classe une fiche PagesJaunes ou un mini-site gratuit en « mini-site d’annuaire »', function (string $url) {
        Http::fake();

        expect(analyzeUrl($url)->state)->toBe(WebsiteState::Directory);
    })->with(['https://www.pagesjaunes.fr/pros/12345678', 'https://plomberie-martin.wixsite.com/accueil', 'http://plombier.pagesperso-orange.fr']);

    it('classe un site qui redirige vers Facebook en « réseau social seul »', function () {
        fakeWeb([
            'https://plomberie-martin.example/' => Http::response('', 301, ['Location' => 'https://www.facebook.com/plomberiemartin']),
            'https://www.facebook.com/*' => Http::response('<html>Facebook</html>'),
        ]);

        expect(analyzeUrl('https://plomberie-martin.example/'))
            ->state->toBe(WebsiteState::SocialOnly)
            ->analysis_note->toContain('facebook.com');
    });

    it('donne l’état « aucun site » à une entreprise sans site', function () {
        Http::fake();
        $company = Company::factory()->create();

        (new AnalyzeWebsite($company))->handle(app(WebsiteAnalyzer::class));

        expect($company->fresh()->website)
            ->state->toBe(WebsiteState::None)
            ->analyzed_at->not->toBeNull();
        Http::assertNothingSent();
    });
});

it('suit les redirections http → https et vers www', function () {
    fakeWeb([
        'https://plomberie-martin.example/' => Http::response('', 301, ['Location' => 'https://www.plomberie-martin.example/']),
        'https://www.plomberie-martin.example/' => Http::response(websiteFixture('modern')),
    ]);

    $website = analyzeUrl('http://plomberie-martin.example');

    expect($website)
        ->state->toBe(WebsiteState::Active)
        ->https->toBeTrue()
        ->final_url->toBe('https://www.plomberie-martin.example/')
        ->http_status->toBe(200);
});

it('suit la redirection d’un site qui n’existe qu’en http vers sa version https', function () {
    fakeWeb([
        'https://exemple-redirige.example/' => connectionError('cURL error 7: Connection refused'),
        'http://exemple-redirige.example/' => Http::response('', 301, ['Location' => 'https://www.exemple-redirige.example/']),
        'https://www.exemple-redirige.example/' => Http::response(websiteFixture('modern')),
    ]);

    expect(analyzeUrl('http://exemple-redirige.example/'))
        ->https->toBeTrue()
        ->final_url->toBe('https://www.exemple-redirige.example/');
});

it('respecte le robots.txt du site', function () {
    Http::fake([
        'https://prive.example/robots.txt' => Http::response("User-agent: *\nDisallow: /"),
        'https://prive.example/' => Http::response(websiteFixture('outdated')),
    ]);

    expect(analyzeUrl('https://prive.example/'))
        ->state->toBe(WebsiteState::Unknown)
        ->analysis_note->toContain('robots.txt');

    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://prive.example/');
});

describe('PageSpeed', function () {
    it('interroge PageSpeed en mobile sur l’adresse finale, avec la clé si elle existe', function () {
        config(['prospection.pagespeed.key' => 'cle-de-test']);
        fakeWeb(['https://plomberie-martin.example/' => Http::response(websiteFixture('modern'))]);

        analyzeUrl('https://plomberie-martin.example/');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pagespeedonline')
            && $request['url'] === 'https://plomberie-martin.example/'
            && $request['strategy'] === 'mobile'
            && $request['key'] === 'cle-de-test');
    });

    it('termine l’analyse sans score si PageSpeed est saturé, puis le laisse en paix une heure', function () {
        fakeWeb([
            'https://plomberie-martin.example/' => Http::response(websiteFixture('modern')),
            PAGESPEED_URL => Http::response(['error' => ['code' => 429]], 429),
        ], pagespeed: null);

        $website = analyzeUrl('https://plomberie-martin.example/');

        expect($website)->state->toBe(WebsiteState::Active)->pagespeed->toBeNull()
            ->analysis_note->toBe('Score PageSpeed indisponible pour le moment.');

        analyzeUrl('https://plomberie-martin.example/');
        expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'pagespeedonline')))
            ->toHaveCount(1); // pas de second appel pendant la pause
    });

    it('n’interroge pas PageSpeed s’il est désactivé', function () {
        config(['prospection.pagespeed.enabled' => false]);
        fakeWeb(['https://plomberie-martin.example/' => Http::response(websiteFixture('modern'))], pagespeed: null);

        expect(analyzeUrl('https://plomberie-martin.example/'))->pagespeed->toBeNull()->analysis_note->toBeNull();
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'pagespeedonline'));
    });
});

describe('déclenchement', function () {
    it('analyse le site après l’enrichissement d’une entreprise', function () {
        Queue::fake([AnalyzeWebsite::class]);
        Http::fake();
        $company = Company::factory()->create(['latitude' => null, 'longitude' => null, 'name' => 'SARL Boulangerie']);

        app()->call([new EnrichCompany($company), 'handle']);

        Queue::assertPushed(AnalyzeWebsite::class, fn (AnalyzeWebsite $job) => $job->company->is($company) && $job->queue === 'enrichment');
    });

    it('met en file l’analyse des sites jamais analysés', function () {
        Queue::fake();
        $never = Website::factory()->create(['analyzed_at' => null])->company;
        Website::factory()->create(['analyzed_at' => now()]);
        $noWebsite = Company::factory()->create();

        $this->artisan('app:analyze-websites')->assertSuccessful();

        Queue::assertPushed(AnalyzeWebsite::class, 2);
        Queue::assertPushed(AnalyzeWebsite::class, fn ($job) => $job->company->is($never));
        Queue::assertPushed(AnalyzeWebsite::class, fn ($job) => $job->company->is($noWebsite));
    });

    it('limite le débit des analyses', function () {
        expect((new AnalyzeWebsite(Company::factory()->make()))->middleware()[0])
            ->toBeInstanceOf(RateLimited::class);
    });
});
