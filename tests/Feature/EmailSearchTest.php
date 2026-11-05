<?php

use App\Enums\ActivityType;
use App\Enums\ContactType;
use App\Enums\ContactVerification;
use App\Enums\DataSource;
use App\Enums\ProspectStatus;
use App\Enums\WebsiteState;
use App\Filament\Pages\CallList;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Jobs\AnalyzeWebsite;
use App\Jobs\FindContactEmails;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Models\Website;
use App\Services\ContactRecorder;
use App\Services\DnsResolver;
use App\Services\EmailExtractor;
use App\Services\EmailFinder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-11-05 10:00:00');
    Sleep::fake();
});

function emailFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/emails/{$name}.html"));
}

/** Entreprise avec un site en ligne, déjà analysé (M5). */
function companyWithSite(string $url = 'https://plombier-martin.fr/', array $attributes = []): Company
{
    $company = Company::factory()->create($attributes + ['phone' => '0385123456', 'status' => ProspectStatus::New, 'is_active' => true]);
    Website::factory()->for($company)->create(['url' => $url, 'final_url' => $url, 'state' => WebsiteState::Active, 'problems' => []]);

    return $company;
}

function fakePlombierSite(string $robots = ''): void
{
    Http::fake([
        'plombier-martin.fr/robots.txt' => Http::response($robots, $robots === '' ? 404 : 200),
        'plombier-martin.fr/' => Http::response(emailFixture('home'), 200, ['Content-Type' => 'text/html; charset=utf-8']),
        'plombier-martin.fr/nous-contacter' => Http::response(emailFixture('contact'), 200, ['Content-Type' => 'text/html']),
        'plombier-martin.fr/mentions-legales.html' => Http::response(emailFixture('legal'), 200, ['Content-Type' => 'text/html']),
    ]);
}

describe('extraction', function () {
    it('extrait un lien mailto: et un email écrit en clair', function () {
        $emails = app(EmailExtractor::class)->extract(emailFixture('home'));

        expect($emails)->toBe(['contact@plombier-martin.fr', 'compta@plombier-martin.fr']);
    });

    it('reconnaît les formes masquées courantes', function (string $html, string $expected) {
        expect(app(EmailExtractor::class)->extract($html))->toBe([$expected]);
    })->with([
        'crochets [at] [dot]' => ['<p>contact [at] plomberie-durand [dot] fr</p>', 'contact@plomberie-durand.fr'],
        'parenthèses (at)' => ['<p>contact (at) plomberie-durand.fr</p>', 'contact@plomberie-durand.fr'],
        'arobase en toutes lettres' => ['<p>contact[arobase]plomberie-durand.fr</p>', 'contact@plomberie-durand.fr'],
        'espaces autour de @' => ['<p>Écrivez à contact @ plomberie-durand.fr</p>', 'contact@plomberie-durand.fr'],
        'entité HTML &#64;' => ['<p>contact&#64;plomberie-durand.fr</p>', 'contact@plomberie-durand.fr'],
        'protection Cloudflare' => ['<a data-cfemail="422336272e2b273002322e2d2f202b27306f2f2330362b2c6c2430">[email protected]</a>', 'atelier@plombier-martin.fr'],
    ]);

    it('ignore les faux emails (images, modèles de formulaire, outils techniques)', function () {
        $html = '<img src="logo@2x.png"><input placeholder="votre@email.fr"><p>votre@email.fr nom@domaine.fr</p>'
            .'<script>var dsn = "abc@sentry.io"</script><p>0123456789abcdef0123456789abcdef@o1.ingest.sentry.io</p>'
            .'<p>test@yopmail.com</p><p>Rendez-vous at home dot com</p>';

        expect(app(EmailExtractor::class)->extract($html))->toBe([]);
    });
});

describe('crawl du site', function () {
    it('visite l’accueil, la page contact et les mentions légales, avec un délai entre les pages', function () {
        fakePlombierSite();
        $company = companyWithSite();

        (new FindContactEmails($company))->handle(app(EmailFinder::class));

        expect($company->contacts()->pluck('email')->sort()->values()->all())->toBe([
            'atelier@plombier-martin.fr',
            'compta@plombier-martin.fr',
            'contact@plombier-martin.fr',
            'devis@plombier-martin.fr',
            'jean.martin71@gmail.com',
        ]);

        // L'email de l'agence web cité dans les mentions légales n'est pas celui de l'entreprise.
        expect(Contact::where('email', 'hello@pixel-web-agence.fr')->exists())->toBeFalse();

        Sleep::assertSleptTimes(2); // entre l'accueil et la page contact, puis les mentions légales
        expect($company->fresh()->emails_searched_at)->not->toBeNull();
    });

    it('enregistre la source, la page et la date de collecte', function () {
        fakePlombierSite();
        $company = companyWithSite();

        (new FindContactEmails($company))->handle(app(EmailFinder::class));

        expect($company->contacts()->firstWhere('email', 'devis@plombier-martin.fr'))
            ->source->toBe(DataSource::Website)
            ->source_url->toBe('https://plombier-martin.fr/nous-contacter')
            ->collected_at->toDateTimeString()->toBe('2026-11-05 10:00:00');

        expect($company->activities()->where('type', ActivityType::Note)->first()->content)->toContain('devis@plombier-martin.fr');
    });

    it('ne visite pas une page interdite par robots.txt', function () {
        fakePlombierSite("User-agent: *\nDisallow: /nous-contacter\n");
        $company = companyWithSite();

        (new FindContactEmails($company))->handle(app(EmailFinder::class));

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/nous-contacter'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/mentions-legales.html'));
        expect($company->contacts()->pluck('email'))->not->toContain('devis@plombier-martin.fr');
    });

    it('ne visite aucune page quand tout le site est interdit par robots.txt', function () {
        fakePlombierSite("User-agent: *\nDisallow: /\n");
        $company = companyWithSite();

        (new FindContactEmails($company))->handle(app(EmailFinder::class));

        Http::assertSentCount(1); // robots.txt uniquement
        expect($company->contacts()->count())->toBe(0);
    });

    it('n’explore ni un site hors ligne, ni une entreprise à ne pas contacter', function () {
        Http::fake();
        $offline = companyWithSite();
        $offline->website->update(['state' => WebsiteState::Offline]);
        $blocked = companyWithSite(attributes: ['status' => ProspectStatus::DoNotContact]);

        (new FindContactEmails($offline))->handle(app(EmailFinder::class));
        (new FindContactEmails($blocked))->handle(app(EmailFinder::class));

        Http::assertNothingSent();
    });

    it('cherche les emails automatiquement après l’analyse d’un site en ligne', function () {
        Queue::fake([FindContactEmails::class]);
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            'https://plombier-martin.fr/' => Http::response(emailFixture('home')),
            'www.googleapis.com/*' => Http::response([], 500),
        ]);
        $company = companyWithSite();
        $company->website->update(['analyzed_at' => null, 'state' => WebsiteState::Unknown]);

        app()->call([new AnalyzeWebsite($company), 'handle']);

        Queue::assertPushed(FindContactEmails::class, fn ($job) => $job->company->is($company));
    });

    it('lance la recherche depuis la fiche et en ligne de commande', function () {
        Queue::fake([FindContactEmails::class]);
        $this->actingAs(User::factory()->withAppAuthentication()->create());
        $company = companyWithSite();
        companyWithSite('https://autre.fr/')->update(['emails_searched_at' => now()]);

        Livewire::test(ViewCompany::class, ['record' => $company->getKey()])->callAction('findEmails');
        $this->artisan('app:find-emails')->expectsOutputToContain('1 recherche(s)')->assertSuccessful();

        Queue::assertPushed(FindContactEmails::class, 2);
    });
});

describe('vérification et classement', function () {
    it('marque invalide une adresse dont le domaine n’a pas de MX', function () {
        app(DnsResolver::class)->withoutMailServer = ['sans-mx.fr'];
        $company = Company::factory()->create();

        $invalid = ContactRecorder::record($company, 'contact@sans-mx.fr', DataSource::Manual);
        $valid = ContactRecorder::record($company, 'contact@avec-mx.fr', DataSource::Manual);

        expect($invalid->fresh())
            ->verification->toBe(ContactVerification::Invalid)
            ->verification_note->toContain('aucun serveur MX')
            ->verified_at->not->toBeNull()
            ->and($valid->fresh()->verification)->toBe(ContactVerification::Valid);
    });

    it('marque invalide une adresse jetable ou mal formée sans interroger le DNS', function () {
        $company = Company::factory()->create();

        $disposable = ContactRecorder::record($company, 'contact@yopmail.com', DataSource::Manual);
        $malformed = ContactRecorder::record($company, 'contact@localhost', DataSource::Manual);

        expect($disposable->fresh()->verification)->toBe(ContactVerification::Invalid)
            ->and($malformed->fresh()->verification)->toBe(ContactVerification::Invalid)
            ->and(app(DnsResolver::class)->mxLookups)->toBe([]);
    });

    it('classe contact@ en générique et une adresse gmail.com en personnelle, à revoir', function () {
        $company = Company::factory()->create();

        $generic = ContactRecorder::record($company, 'contact@plombier-martin.fr', DataSource::Website);
        $nominative = ContactRecorder::record($company, 'jean.martin@plombier-martin.fr', DataSource::Website);
        $personal = ContactRecorder::record($company, 'jean.martin71@gmail.com', DataSource::Website);

        expect($generic->type)->toBe(ContactType::Generic)
            ->and($generic->needsReview())->toBeFalse()
            ->and($nominative->type)->toBe(ContactType::Nominative)
            ->and($personal->type)->toBe(ContactType::Personal)
            ->and($personal->needsReview())->toBeTrue();
    });

    it('affiche les adresses personnelles dans l’onglet « À revoir » et permet de les valider', function () {
        $this->actingAs(User::factory()->withAppAuthentication()->create());
        $company = Company::factory()->create();
        $personal = ContactRecorder::record($company, 'jean.martin71@gmail.com', DataSource::Website);
        $generic = ContactRecorder::record($company, 'contact@plombier-martin.fr', DataSource::Website);

        Livewire::test(ManageContacts::class)
            ->set('activeTab', 'a_revoir')
            ->assertCanSeeTableRecords([$personal])
            ->assertCanNotSeeTableRecords([$generic])
            ->callTableAction('approve', $personal);

        expect($personal->fresh()->needsReview())->toBeFalse()
            ->and($personal->fresh()->reviewed_at)->not->toBeNull();
    });

    it('vérifie en ligne de commande les adresses encore « À vérifier »', function () {
        $contact = Contact::factory()->create(['verification' => ContactVerification::Valid]);
        $contact->updateQuietly(['verification' => ContactVerification::Pending]);

        $this->artisan('app:verify-emails')->expectsOutputToContain('1 vérification(s)')->assertSuccessful();

        expect($contact->fresh()->verification)->toBe(ContactVerification::Valid);
    });
});

describe('liste d’appels', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->withAppAuthentication()->create());
    });

    it('contient les prospects sans email utilisable mais avec un téléphone', function () {
        $phoneOnly = Company::factory()->create(['phone' => '0385000001', 'score' => 80]);
        $invalidEmail = Company::factory()->create(['phone' => '0385000002', 'score' => 60]);
        Contact::factory()->for($invalidEmail)->create(['verification' => ContactVerification::Invalid]);

        $withEmail = Company::factory()->create(['phone' => '0385000003']);
        Contact::factory()->for($withEmail)->create();
        $noPhone = Company::factory()->create(['phone' => null]);
        $blocked = Company::factory()->create(['phone' => '0385000004', 'status' => ProspectStatus::DoNotContact]);
        $closed = Company::factory()->create(['phone' => '0385000005', 'is_active' => false]);

        Livewire::test(CallList::class)
            ->assertCanSeeTableRecords([$phoneOnly, $invalidEmail], inOrder: true)
            ->assertCanNotSeeTableRecords([$withEmail, $noPhone, $blocked, $closed]);
    });

    it('sort de la liste quand un email est trouvé', function () {
        $company = Company::factory()->create(['phone' => '0385000001']);
        expect(Company::query()->toCall()->pluck('id')->all())->toBe([$company->id]);

        ContactRecorder::record($company, 'contact@trouve.fr', DataSource::Website);

        expect(Company::query()->toCall()->count())->toBe(0);
    });

    it('note le résultat d’un appel dans l’historique', function () {
        $company = Company::factory()->create(['phone' => '0385000001']);

        Livewire::test(CallList::class)
            ->callTableAction('logCall', $company, data: ['outcome' => 'call_back', 'call_back_on' => '2026-11-10', 'note' => 'Rappeler après 14 h'])
            ->assertHasNoTableActionErrors();

        expect($company->fresh())
            ->status->toBe(ProspectStatus::New)
            ->next_action_on->toDateString()->toBe('2026-11-10')
            ->and($company->activities()->first())
            ->type->toBe(ActivityType::Call)
            ->content->toBe('À rappeler. Rappeler après 14 h');

        Livewire::test(CallList::class)->callTableAction('logCall', $company, data: ['outcome' => 'do_not_contact']);

        expect($company->fresh()->status)->toBe(ProspectStatus::DoNotContact)
            ->and(Company::query()->toCall()->count())->toBe(0);
    });
});
