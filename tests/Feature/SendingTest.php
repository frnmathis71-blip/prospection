<?php

use App\Enums\ActivityType;
use App\Enums\CampaignStatus;
use App\Enums\ContactType;
use App\Enums\ContactVerification;
use App\Enums\EmailStatus;
use App\Enums\ProspectStatus;
use App\Enums\SuppressionReason;
use App\Enums\WebsiteState;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Jobs\SendNextEmail;
use App\Mail\ProspectMail;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Email;
use App\Models\Sequence;
use App\Models\SuppressionEntry;
use App\Models\Template;
use App\Models\User;
use App\Models\Website;
use App\Services\CampaignAudience;
use App\Services\EmailSender;
use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'prospection.sender' => [
            'name' => 'Camille Exemple', 'company' => 'Codenyr', 'address' => '1 place de l’Exemple, 71100 Chalon',
            'siret' => '000 000 000 00000', 'phone' => null, 'website' => 'https://codenyr.fr', 'privacy_url' => 'https://codenyr.fr/confidentialite',
        ],
        'prospection.sending.daily_cap' => 100,
        'prospection.sending.spacing_minutes' => [2, 6],
        'mail.from.address' => 'camille@envoi-dedie.example',
    ]);
    Mail::fake();
    parisTime('2026-11-09 08:00'); // lundi
});

function parisTime(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, 'Europe/Paris'));
}

/** Campagne lancée vers $count prospects sans site, chacun avec une adresse vérifiée. */
function activeCampaign(int $count = 3, array $attributes = []): Campaign
{
    $template = Template::factory()->create(['subject' => 'Bonjour {nom_entreprise}', 'body' => 'Bonjour {nom_entreprise}, je crée des sites.']);
    $sequence = Sequence::factory()->create();
    $sequence->steps()->create(['position' => 1, 'delay_days' => 0, 'template_id' => $template->id]);

    $campaign = Campaign::factory()->for($sequence)->create($attributes + ['search_id' => null, 'daily_quota' => 50, 'hourly_quota' => 30, 'status' => CampaignStatus::Draft]);

    foreach (range(1, $count) as $i) {
        $company = Company::factory()->create(['name' => "Entreprise {$i}", 'score' => 100 - $i, 'status' => ProspectStatus::New]);
        Website::factory()->none()->for($company)->create();
        Contact::factory()->for($company)->create(['email' => "contact@entreprise-{$i}.fr"]);
    }

    app(CampaignAudience::class)->enqueue($campaign);
    $campaign->update(['status' => CampaignStatus::Active, 'started_at' => now()]);

    return $campaign;
}

function sendNext(): ?Email
{
    return app(EmailSender::class)->sendNext();
}

/** Appelle l'envoi toutes les 7 minutes (plus que l'espacement maximal) entre deux heures du même jour. */
function runDay(string $day, string $from = '08:00', string $to = '17:00'): void
{
    $moment = CarbonImmutable::parse("{$day} {$from}", 'Europe/Paris');
    $end = CarbonImmutable::parse("{$day} {$to}", 'Europe/Paris');

    while ($moment->lt($end)) {
        test()->travelTo($moment);
        sendNext();
        $moment = $moment->addMinutes(7);
    }
}

describe('lancement', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->withAppAuthentication()->create());
    });

    it('crée la campagne en brouillon et ne la lance qu’avec une confirmation explicite', function () {
        $template = Template::factory()->create();
        $sequence = Sequence::factory()->create();
        $sequence->steps()->create(['position' => 1, 'delay_days' => 0, 'template_id' => $template->id]);
        $company = Company::factory()->create();
        Website::factory()->none()->for($company)->create();
        Contact::factory()->for($company)->create();

        Livewire::test(CreateCampaign::class)
            ->fillForm(['name' => 'Pilote', 'sequence_id' => $sequence->id, 'daily_quota' => 10, 'hourly_quota' => 3, 'criteria' => ['limit' => 10]])
            ->call('create')
            ->assertHasNoFormErrors();

        $campaign = Campaign::firstWhere('name', 'Pilote');
        expect($campaign->status)->toBe(CampaignStatus::Draft)->and($campaign->emails()->count())->toBe(0);

        Livewire::test(ViewCampaign::class, ['record' => $campaign->getKey()])
            ->callAction('launch', data: ['confirm' => false])
            ->assertHasActionErrors(['confirm']);

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);

        Livewire::test(ViewCampaign::class, ['record' => $campaign->getKey()])
            ->callAction('launch', data: ['confirm' => true])
            ->assertHasNoActionErrors();

        expect($campaign->fresh())->status->toBe(CampaignStatus::Active)->started_at->not->toBeNull()
            ->and($campaign->emails()->where('status', EmailStatus::Queued)->count())->toBe(1);

        Mail::assertNothingSent(); // rien ne part au lancement : seulement par la file, dans les créneaux
    });

    it('refuse de lancer tant que l’identité de l’expéditeur est incomplète', function () {
        config(['prospection.sender.siret' => null]);
        $campaign = activeCampaign(1);
        $campaign->emails()->delete();
        $campaign->update(['status' => CampaignStatus::Draft]);

        Livewire::test(ViewCampaign::class, ['record' => $campaign->getKey()])->callAction('launch', data: ['confirm' => true]);

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft)->and($campaign->emails()->count())->toBe(0);
    });
});

describe('liste ciblée', function () {
    it('ne garde que les adresses envoyables, une seule par entreprise, la générique d’abord', function () {
        $campaign = Campaign::factory()->create(['search_id' => null]);

        $twoContacts = Company::factory()->create(['score' => 90]);
        Contact::factory()->nominative()->for($twoContacts)->create();
        $generic = Contact::factory()->for($twoContacts)->create(['email' => 'contact@deux.fr']);

        $personal = Company::factory()->create();
        Contact::factory()->for($personal)->create(['email' => 'artisan@gmail.com', 'type' => ContactType::Personal]);
        $reviewed = Company::factory()->create();
        Contact::factory()->for($reviewed)->create(['email' => 'valide@gmail.com', 'type' => ContactType::Personal, 'reviewed_at' => now()]);
        $invalid = Company::factory()->create();
        Contact::factory()->for($invalid)->create(['verification' => ContactVerification::Invalid]);
        $suppressed = Company::factory()->create();
        Contact::factory()->for($suppressed)->create(['email' => 'stop@oppose.fr']);
        SuppressionEntry::create(['domain' => 'oppose.fr', 'reason' => SuppressionReason::Manual]);
        $contacted = Company::factory()->create(['status' => ProspectStatus::Contacted]);
        Contact::factory()->for($contacted)->create();

        $recipients = app(CampaignAudience::class)->recipients($campaign);

        expect($recipients->map(fn ($r) => $r['contact']->email)->sort()->values()->all())->toBe(['contact@deux.fr', 'valide@gmail.com'])
            ->and($recipients->firstWhere('company.id', $twoContacts->id)['contact']->is($generic))->toBeTrue();
    });

    it('applique les critères (état du site, score, nombre maximum)', function () {
        $campaign = Campaign::factory()->create(['search_id' => null, 'criteria' => ['website_states' => ['none'], 'min_score' => 50, 'limit' => 2]]);

        foreach ([[WebsiteState::None, 90], [WebsiteState::None, 80], [WebsiteState::None, 70], [WebsiteState::None, 30], [WebsiteState::Active, 95]] as [$state, $score]) {
            $company = Company::factory()->create(['score' => $score]);
            Website::factory()->for($company)->create(['state' => $state, 'problems' => []]);
            Contact::factory()->for($company)->create();
        }

        expect(app(CampaignAudience::class)->recipients($campaign)->map(fn ($r) => $r['company']->score)->all())->toBe([90, 80]);
    });

    it('ne contacte jamais deux fois une entreprise dans la même campagne', function () {
        $campaign = activeCampaign(2);

        expect(app(CampaignAudience::class)->enqueue($campaign))->toBe(0)
            ->and($campaign->emails()->count())->toBe(2);

        // Même forcé, la base refuse un doublon.
        $email = $campaign->emails()->first();
        expect(fn () => Email::create($email->only(['campaign_id', 'company_id', 'sequence_step_id', 'contact_id'])))
            ->toThrow(UniqueConstraintViolationException::class);

        runDay('2026-11-09');
        Mail::assertSentCount(2);
    });
});

describe('créneaux et quotas', function () {
    it('ne dépasse jamais le plafond quotidien : l’excédent part le jour ouvré suivant', function () {
        $campaign = activeCampaign(5, ['daily_quota' => 3]);

        runDay('2026-11-09'); // lundi
        expect($campaign->emails()->where('status', EmailStatus::Sent)->count())->toBe(3);
        Mail::assertSentCount(3);

        runDay('2026-11-10'); // mardi
        expect($campaign->emails()->where('status', EmailStatus::Sent)->count())->toBe(5);
        expect($campaign->emails()->whereBetween('sent_at', BusinessCalendar::dayBounds(CarbonImmutable::parse('2026-11-10 12:00', 'Europe/Paris')))->count())->toBe(2);
    });

    it('applique aussi le plafond global du domaine, toutes campagnes confondues', function () {
        config(['prospection.sending.daily_cap' => 4]);
        activeCampaign(3);
        activeCampaign(3);

        runDay('2026-11-09');

        Mail::assertSentCount(4);
    });

    it('respecte le quota horaire', function () {
        activeCampaign(5, ['hourly_quota' => 2]);

        runDay('2026-11-09', '08:00', '09:00');

        Mail::assertSentCount(2);
    });

    it('n’envoie rien le week-end, un jour férié ni hors des créneaux', function (string $moment) {
        activeCampaign(1);
        parisTime($moment);

        expect(sendNext())->toBeNull();
        Mail::assertNothingSent();
    })->with([
        'samedi 10 h' => '2026-11-07 10:00',
        'dimanche 15 h' => '2026-11-08 15:00',
        'lundi 7 h 59' => '2026-11-09 07:59',
        'lundi 11 h' => '2026-11-09 11:00',
        'lundi 12 h 30' => '2026-11-09 12:30',
        'lundi 17 h' => '2026-11-09 17:00',
        '11 novembre (férié)' => '2026-11-11 10:00',
        'lundi de Pâques 2027' => '2027-03-29 10:00',
    ]);

    it('envoie dans les créneaux du matin et de l’après-midi', function (string $moment) {
        activeCampaign(1);
        parisTime($moment);

        expect(sendNext()?->status)->toBe(EmailStatus::Sent);
    })->with(['lundi 8 h' => '2026-11-09 08:00', 'lundi 10 h 59' => '2026-11-09 10:59', 'lundi 14 h' => '2026-11-09 14:00', 'vendredi 16 h 30' => '2026-11-13 16:30']);

    it('espace deux envois de 2 à 6 minutes, au hasard', function () {
        activeCampaign(3);

        expect(sendNext())->not->toBeNull();
        parisTime('2026-11-09 08:01');
        expect(sendNext())->toBeNull();
        parisTime('2026-11-09 08:06');
        expect(sendNext())->not->toBeNull();

        Mail::assertSentCount(2);
    });

    it('ne fait rien pour une campagne en brouillon ou en pause', function () {
        $campaign = activeCampaign(2);
        $campaign->update(['status' => CampaignStatus::Paused]);

        runDay('2026-11-09');

        Mail::assertNothingSent();
    });
});

describe('contrôles de dernière minute', function () {
    it('n’envoie pas à une adresse désinscrite après la mise en file', function () {
        $campaign = activeCampaign(2);
        SuppressionEntry::create(['email' => 'contact@entreprise-1.fr', 'reason' => SuppressionReason::Unsubscribed]);

        $email = sendNext();

        expect($campaign->emails()->firstWhere('contact_id', Contact::firstWhere('email', 'contact@entreprise-1.fr')->id))
            ->status->toBe(EmailStatus::Cancelled)
            ->note->toBe('Adresse ou domaine sur la liste d’opposition.')
            ->and($email->contact->email)->toBe('contact@entreprise-2.fr');

        Mail::assertNotSent(ProspectMail::class, fn (ProspectMail $mail) => $mail->hasTo('contact@entreprise-1.fr'));
        Mail::assertSent(ProspectMail::class, fn (ProspectMail $mail) => $mail->hasTo('contact@entreprise-2.fr'));
    });

    it('annule l’envoi si l’entreprise est passée « Ne pas contacter » ou si l’adresse est devenue invalide', function () {
        $campaign = activeCampaign(2);
        Company::firstWhere('name', 'Entreprise 1')->update(['status' => ProspectStatus::DoNotContact]);
        Contact::firstWhere('email', 'contact@entreprise-2.fr')->update(['verification' => ContactVerification::Invalid]);

        runDay('2026-11-09');

        Mail::assertNothingSent();
        expect($campaign->emails()->pluck('status')->unique()->all())->toBe([EmailStatus::Cancelled])
            ->and($campaign->fresh()->status)->toBe(CampaignStatus::Completed);
    });

    it('annule l’envoi si le rendu est bloqué (identité de l’expéditeur retirée)', function () {
        activeCampaign(1);
        config(['prospection.sender.address' => null]);

        expect(sendNext())->status->toBe(EmailStatus::Cancelled)->note->toContain('Rendu bloqué');
        Mail::assertNothingSent();
    });
});

describe('email envoyé', function () {
    it('porte les en-têtes de désinscription en un clic, son Message-ID, et met à jour le prospect', function () {
        activeCampaign(1);

        $email = sendNext();
        $company = Company::firstWhere('name', 'Entreprise 1');

        Mail::assertSent(ProspectMail::class, function (ProspectMail $mail) use ($email) {
            $headers = $mail->headers();

            return $mail->hasTo('contact@entreprise-1.fr')
                && str_starts_with($headers->text['List-Unsubscribe'], '<http')
                && $headers->text['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click'
                && '<'.$headers->messageId.'>' === $email->message_id
                && str_ends_with($headers->messageId, '@envoi-dedie.example');
        });

        expect($email)->status->toBe(EmailStatus::Sent)->subject->toBe('Bonjour Entreprise 1')->sent_at->not->toBeNull()
            ->and($company->status)->toBe(ProspectStatus::Contacted)
            ->and($company->activities()->first()->type)->toBe(ActivityType::EmailSent);
    });

    it('a un lien de désinscription qui fonctionne sans connexion et ajoute l’adresse à la liste d’opposition', function () {
        activeCampaign(1);
        sendNext();

        $url = null;
        Mail::assertSent(ProspectMail::class, function (ProspectMail $mail) use (&$url) {
            $url = $mail->email->unsubscribeUrl;

            return true;
        });

        auth()->logout();
        $this->get($url)->assertOk();

        expect(SuppressionEntry::blocks('contact@entreprise-1.fr'))->toBeTrue()
            ->and(Company::firstWhere('name', 'Entreprise 1')->status)->toBe(ProspectStatus::Unsubscribed);
    });

    it('passe par la file d’attente (job SendNextEmail, sans nouvelle tentative)', function () {
        activeCampaign(1);

        SendNextEmail::dispatch();

        Mail::assertSentCount(1);
        expect((new SendNextEmail)->tries)->toBe(1);
    });
});
