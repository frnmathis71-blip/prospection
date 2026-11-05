<?php

use App\Enums\ActivityType;
use App\Enums\EmailStatus;
use App\Enums\ProspectStatus;
use App\Enums\SuppressionReason;
use App\Filament\Pages\Registry;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Jobs\ReadInbox;
use App\Jobs\SendNextEmail;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Email;
use App\Models\InboundMessage;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Models\Website;
use App\Services\PersonalDataExporter;
use App\Services\ProductionCheck;
use App\Services\ProspectPurger;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2029-12-01 10:00:00');
});

/** Prospect collecté à une date donnée, avec une adresse, un site et un email envoyé (ou non). */
function prospectFrom(string $collectedAt, ?string $lastSentAt = null, array $attributes = [], EmailStatus $status = EmailStatus::Sent): Company
{
    test()->travelTo($collectedAt);
    $company = Company::factory()->create($attributes + ['status' => ProspectStatus::Contacted]);
    Website::factory()->none()->for($company)->create();
    $contact = Contact::factory()->for($company)->create(['email' => 'contact@'.Str::slug($company->name).'-'.$company->id.'.fr']);

    if ($lastSentAt) {
        test()->travelTo($lastSentAt);
        Email::factory()->create(['contact_id' => $contact->id, 'company_id' => $company->id, 'status' => $status, 'sent_at' => now()]);
    }

    test()->travelTo('2029-12-01 10:00:00');

    return $company;
}

describe('purge RGPD', function () {
    it('supprime les prospects sans réponse depuis plus de 3 ans, mais conserve la liste d’opposition', function () {
        $old = prospectFrom('2026-01-10', '2026-02-01');
        $neverContacted = prospectFrom('2026-05-01', attributes: ['status' => ProspectStatus::New]);
        $recentContact = prospectFrom('2026-01-10', '2027-03-01');
        $replied = prospectFrom('2026-01-10', '2026-02-01', status: EmailStatus::Replied);
        $client = prospectFrom('2026-01-10', '2026-02-01', ['status' => ProspectStatus::Won]);
        $unsubscribed = prospectFrom('2026-01-10', '2026-02-01', ['status' => ProspectStatus::Unsubscribed]);
        $unsubscribedEmail = $unsubscribed->contacts()->value('email');
        SuppressionEntry::create(['email' => $unsubscribedEmail, 'reason' => SuppressionReason::Unsubscribed]);
        SuppressionEntry::create(['domain' => 'concurrent.fr', 'reason' => SuppressionReason::Manual]);
        InboundMessage::create(['message_key' => 'x@y', 'kind' => 'auto_reply', 'from' => $old->contacts()->value('email')]);

        $this->artisan('app:purge-prospects --dry-run')->expectsOutputToContain('3 prospect(s) seraient supprimés')->assertSuccessful();
        expect(Company::count())->toBe(6);

        $this->artisan('app:purge-prospects')->expectsOutputToContain('3 prospect(s) supprimés')->assertSuccessful();

        expect(Company::pluck('id')->sort()->values()->all())->toBe(collect([$recentContact, $replied, $client])->pluck('id')->sort()->values()->all())
            ->and(Contact::whereIn('company_id', [$old->id, $neverContacted->id, $unsubscribed->id])->count())->toBe(0)
            ->and(Website::where('company_id', $old->id)->exists())->toBeFalse()
            ->and(InboundMessage::count())->toBe(0)
            // La liste d'opposition survit : l'adresse désinscrite reste bloquée, même après la purge.
            ->and(SuppressionEntry::count())->toBe(2)
            ->and(SuppressionEntry::blocks($unsubscribedEmail))->toBeTrue();
    });

    it('compte une note récente comme une interaction', function () {
        $company = prospectFrom('2026-01-10', '2026-02-01');
        $company->activities()->create(['type' => ActivityType::Call, 'content' => 'Rappeler en janvier', 'occurred_at' => now()->subMonth()]);

        expect(app(ProspectPurger::class)->candidates()->count())->toBe(0);
    });
});

describe('export d’une personne', function () {
    it('contient toutes ses données et leur source', function () {
        config(['prospection.sender.name' => 'Camille Exemple', 'prospection.sender.siret' => '000 000 000 00000']);
        $company = Company::factory()->create(['name' => 'Plomberie Martin', 'siren' => '123456789', 'phone' => '0385123456', 'status' => ProspectStatus::Contacted]);
        Website::factory()->for($company)->create(['url' => 'https://plomberie-martin.fr/', 'state' => 'outdated', 'problems' => ['no_https']]);
        Contact::factory()->for($company)->create([
            'email' => 'contact@plomberie-martin.fr', 'source' => 'website', 'source_url' => 'https://plomberie-martin.fr/contact', 'collected_at' => '2029-11-01 09:00:00',
        ]);
        $campaign = Campaign::factory()->create(['name' => 'Pilote', 'search_id' => null]);
        Email::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => Contact::first()->id, 'company_id' => $company->id, 'subject' => 'Votre site', 'status' => EmailStatus::Replied]);
        $company->activities()->create(['type' => ActivityType::EmailReceived, 'content' => 'Réponse : intéressé', 'occurred_at' => now()]);
        InboundMessage::create(['message_key' => 'r@y', 'kind' => 'reply', 'from' => 'contact@plomberie-martin.fr', 'subject' => 'Re: Votre site', 'received_at' => now()]);
        SuppressionEntry::create(['domain' => 'plomberie-martin.fr', 'reason' => SuppressionReason::Manual]);

        $export = app(PersonalDataExporter::class)->export('Contact@Plomberie-Martin.fr');

        expect($export['adresse'])->toBe('contact@plomberie-martin.fr')
            ->and($export['responsable_du_traitement'])->toMatchArray(['nom' => 'Camille Exemple', 'siret' => '000 000 000 00000'])
            ->and($export['adresses'][0])->toMatchArray([
                'email' => 'contact@plomberie-martin.fr',
                'source' => "Site de l'entreprise",
                'page_source' => 'https://plomberie-martin.fr/contact',
            ])
            ->and($export['adresses'][0]['collectee_le'])->toStartWith('2029-11-01')
            ->and($export['entreprises'][0])->toMatchArray(['nom' => 'Plomberie Martin', 'siren' => '123456789', 'telephone' => '0385123456'])
            ->and($export['entreprises'][0]['site']['problemes_detectes'])->toBe(['Le site n’est pas sécurisé (pas de HTTPS)'])
            ->and($export['entreprises'][0]['historique'][0]['contenu'])->toBe('Réponse : intéressé')
            ->and($export['emails_envoyes'][0])->toMatchArray(['objet' => 'Votre site', 'campagne' => 'Pilote', 'statut' => 'Répondu'])
            ->and($export['messages_recus'][0])->toMatchArray(['objet' => 'Re: Votre site', 'nature' => 'reply'])
            ->and($export['liste_d_opposition'][0])->toMatchArray(['valeur' => '@plomberie-martin.fr', 'raison' => 'Ajout manuel']);
    });

    it('s’exporte en ligne de commande et depuis Filament', function () {
        Storage::fake('local');
        $contact = Contact::factory()->create(['email' => 'contact@exemple-export.fr']);

        $this->artisan('app:export-person contact@exemple-export.fr')->expectsOutputToContain('Export enregistré')->assertSuccessful();
        expect(Storage::disk('local')->files('exports'))->toHaveCount(1);

        $this->actingAs(User::factory()->withAppAuthentication()->create());
        Livewire::test(Registry::class)->callAction('exportPerson', data: ['email' => 'contact@exemple-export.fr'])->assertFileDownloaded('donnees-contact-at-exemple-exportfr.json');
        Livewire::test(ManageContacts::class)->callTableAction('exportData', $contact)->assertFileDownloaded();
    });
});

describe('mise en production', function () {
    it('déclare toutes les tâches planifiées (envois, relances, boîte, purge, sauvegardes, recherches)', function () {
        $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event instanceof CallbackEvent
            ? $event->getSummaryForDisplay()
            : $event->command ?? $event->description);
        $all = $events->implode("\n");

        expect($all)
            ->toContain(SendNextEmail::class)   // envois et relances (J+4, J+12)
            ->toContain(ReadInbox::class)       // réponses et rebonds
            ->toContain('coupe-circuit')
            ->toContain('app:purge-prospects')
            ->toContain('backup:run --only-db')
            ->toContain('backup:clean')
            ->toContain('backup:monitor')
            ->toContain('app:run-recurring-searches weekly')
            ->toContain('app:run-recurring-searches monthly');
    });

    it('signale ce qui manque avant la mise en production', function () {
        config(['app.debug' => true, 'app.url' => 'http://codenyr-prospection.test', 'mail.default' => 'log', 'prospection.sender.siret' => null]);

        $problems = app(ProductionCheck::class)->problems();

        expect($problems)->toContain(
            'APP_ENV doit valoir « production ».',
            'APP_DEBUG doit valoir false (sinon les erreurs affichent des données).',
            'APP_URL doit commencer par https:// (liens de désinscription des emails).',
            'MAIL_MAILER ne doit pas être « log » : configurer le SMTP du domaine d’envoi dédié.',
            'SENDER_SIRET manquant : identité de l’expéditeur obligatoire dans chaque email.',
        );
        $this->artisan('app:production-check')->assertFailed();
    });

    it('affiche le registre du traitement', function () {
        $this->actingAs(User::factory()->withAppAuthentication()->create());
        SuppressionEntry::create(['email' => 'stop@exemple.fr', 'reason' => SuppressionReason::Unsubscribed]);

        Livewire::test(Registry::class)
            ->assertSee('Finalité')
            ->assertSee('Intérêt légitime')
            ->assertSee('Durée de conservation')
            ->assertSee('Liste d’opposition');

        $this->get('/admin/registre')->assertOk();
    });

    it('n’expose rien sans connexion, hors la désinscription signée', function () {
        foreach (['/admin', '/admin/registre', '/admin/campaigns', '/horizon'] as $url) {
            expect($this->get($url)->status())->toBeIn([302, 403]);
        }
    });
});
