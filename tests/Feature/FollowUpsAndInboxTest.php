<?php

use App\Enums\ActivityType;
use App\Enums\CampaignStatus;
use App\Enums\ContactVerification;
use App\Enums\EmailStatus;
use App\Enums\ProspectStatus;
use App\Enums\SuppressionReason;
use App\Jobs\ReadInbox;
use App\Mail\ProspectMail;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Email;
use App\Models\InboundMessage as InboundRecord;
use App\Models\Sequence;
use App\Models\SuppressionEntry;
use App\Models\Template;
use App\Models\User;
use App\Models\Website;
use App\Services\CampaignAudience;
use App\Services\EmailSender;
use App\Services\Inbox\InboundMessage;
use App\Services\Inbox\InboxProcessor;
use App\Services\Inbox\Mailbox;
use App\Services\TemplateRenderer;
use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'prospection.sender' => [
            'name' => 'Camille Exemple', 'company' => 'Codenyr', 'address' => '1 place de l’Exemple, 71100 Chalon',
            'siret' => '000 000 000 00000', 'phone' => null, 'website' => 'https://codenyr.fr', 'privacy_url' => 'https://codenyr.fr/confidentialite',
        ],
        'prospection.sending.daily_cap' => 100,
        'mail.from.address' => 'camille@envoi-dedie.example',
    ]);
    Mail::fake();
});

function at(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, 'Europe/Paris'));
}

/** Séquence J0 / J+4 / J+12 lancée vers une entreprise ; le J0 part le lundi 9 novembre 2026 à 8 h. */
function sequenceStarted(): Email
{
    $templates = collect(['Premier contact', 'Relance', 'Dernière relance'])
        ->map(fn (string $name) => Template::factory()->create(['name' => $name, 'subject' => "{$name} — {nom_entreprise}", 'body' => 'Bonjour {nom_entreprise}.']));
    $sequence = Sequence::factory()->create();
    foreach ([[1, 0], [2, 4], [3, 12]] as $i => [$position, $delay]) {
        $sequence->steps()->create(['position' => $position, 'delay_days' => $delay, 'template_id' => $templates[$i]->id]);
    }

    $campaign = Campaign::factory()->for($sequence)->create(['search_id' => null, 'daily_quota' => 50, 'hourly_quota' => 30]);
    $company = Company::factory()->create(['name' => 'Entreprise 1', 'status' => ProspectStatus::New]);
    Website::factory()->none()->for($company)->create();
    Contact::factory()->for($company)->create(['email' => 'contact@entreprise-1.fr']);

    at('2026-11-09 08:00');
    app(CampaignAudience::class)->enqueue($campaign);
    $campaign->update(['status' => CampaignStatus::Active, 'started_at' => now()]);

    return app(EmailSender::class)->sendNext();
}

/** Appelle l'envoi toutes les 7 minutes pendant les créneaux d'une journée. */
function workDay(string $day): void
{
    $moment = CarbonImmutable::parse("{$day} 07:30", 'Europe/Paris');

    while ($moment->format('H:i') < '17:00') {
        test()->travelTo($moment);
        app(EmailSender::class)->sendNext();
        $moment = $moment->addMinutes(7);
    }
}

function inbound(string $fixture, Email $email, int $n = 1): InboundMessage
{
    $raw = str_replace(['{{MESSAGE_ID}}', '{{N}}'], [trim($email->message_id, '<>'), (string) $n], file_get_contents(base_path("tests/Fixtures/inbox/{$fixture}.eml")));

    return InboundMessage::fromRaw($raw);
}

function sentSubjects(): array
{
    $subjects = [];
    Mail::assertSent(ProspectMail::class, function (ProspectMail $mail) use (&$subjects) {
        $subjects[] = $mail->email->subject;

        return true;
    });

    return $subjects;
}

describe('relances', function () {
    it('compte les jours ouvrés (week-ends et jours fériés exclus)', function () {
        $j0 = CarbonImmutable::parse('2026-11-09 08:00', 'Europe/Paris');

        expect(BusinessCalendar::addBusinessDays($j0, 4)->toDateString())->toBe('2026-11-16') // 11 novembre férié
            ->and(BusinessCalendar::addBusinessDays($j0, 12)->toDateString())->toBe('2026-11-26');
    });

    it('envoie chaque relance au bon jour ouvré, avec le bon template, dans la même conversation', function () {
        $first = sequenceStarted();
        expect($first->status)->toBe(EmailStatus::Sent);

        foreach (['2026-11-10', '2026-11-12', '2026-11-13'] as $day) {
            workDay($day);
        }
        expect(sentSubjects())->toBe(['Premier contact — Entreprise 1']);

        workDay('2026-11-16'); // J+4
        expect(sentSubjects())->toBe(['Premier contact — Entreprise 1', 'Relance — Entreprise 1'])
            ->and(Company::first()->status)->toBe(ProspectStatus::FollowedUp);

        Mail::assertSent(ProspectMail::class, fn (ProspectMail $mail) => $mail->email->subject === 'Relance — Entreprise 1'
            && $mail->headers()->references === [trim($first->message_id, '<>')]
            && $mail->headers()->text['In-Reply-To'] === $first->message_id);

        workDay('2026-11-25');
        expect(sentSubjects())->toHaveCount(2);

        workDay('2026-11-26'); // J+12
        expect(sentSubjects())->toBe(['Premier contact — Entreprise 1', 'Relance — Entreprise 1', 'Dernière relance — Entreprise 1'])
            ->and(Campaign::first()->status)->toBe(CampaignStatus::Completed);

        workDay('2026-12-07');
        expect(sentSubjects())->toHaveCount(3); // jamais plus de 3 emails
    });

    it('garde la campagne active tant qu’une relance reste à venir', function () {
        sequenceStarted();
        workDay('2026-11-10');

        expect(Campaign::first()->status)->toBe(CampaignStatus::Active);
    });

    it('annule les relances restantes en cas de désinscription en cours de séquence', function () {
        $first = sequenceStarted();
        at('2026-11-16 07:00'); // la relance J+4 est mise en file, mais rien ne part avant 8 h
        app(EmailSender::class)->sendNext();
        $followUp = Email::query()->where('status', EmailStatus::Queued)->sole();

        $this->get(TemplateRenderer::unsubscribeUrl('contact@entreprise-1.fr'))->assertOk();

        expect($followUp->fresh())->status->toBe(EmailStatus::Cancelled)->note->toBe('Désinscription : séquence arrêtée.');

        workDay('2026-11-16');
        workDay('2026-11-26');
        expect(sentSubjects())->toBe(['Premier contact — Entreprise 1']);
    });
});

describe('réponses', function () {
    it('reconnaît une réponse (In-Reply-To / References), arrête la séquence et prévient l’utilisateur', function () {
        $user = User::factory()->withAppAuthentication()->create();
        $first = sequenceStarted();
        at('2026-11-10 09:15');

        $kind = app(InboxProcessor::class)->process(inbound('reply', $first));

        $company = Company::first();
        expect($kind)->toBe(InboxProcessor::REPLY)
            ->and($first->fresh()->status)->toBe(EmailStatus::Replied)
            ->and($company->status)->toBe(ProspectStatus::Replied)
            ->and($company->activities()->where('type', ActivityType::EmailReceived)->first()->content)
            ->toContain('Oui, ça m\'intéresse')
            ->not->toContain('> Bonjour')
            ->and($user->notifications()->count())->toBe(1)
            ->and($user->notifications()->first()->data['title'])->toBe('Entreprise 1 a répondu');

        workDay('2026-11-16');
        workDay('2026-11-26');
        expect(sentSubjects())->toHaveCount(1);
    });

    it('ignore une réponse automatique d’absence : la séquence continue', function () {
        $first = sequenceStarted();

        $kind = app(InboxProcessor::class)->process(inbound('auto-reply', $first));

        expect($kind)->toBe(InboxProcessor::AUTO_REPLY)
            ->and($first->fresh()->status)->toBe(EmailStatus::Sent)
            ->and(Company::first()->status)->toBe(ProspectStatus::Contacted);

        workDay('2026-11-16');
        expect(sentSubjects())->toHaveCount(2);
    });

    it('reconnaît une réponse sans en-têtes de conversation par l’adresse de l’expéditeur', function () {
        $first = sequenceStarted();
        $raw = "From: contact@entreprise-1.fr\r\nTo: camille@envoi-dedie.example\r\nSubject: Votre message\r\nMessage-ID: <sans-fil@entreprise-1.fr>\r\n\r\nRappelez-moi.\r\n";

        expect(app(InboxProcessor::class)->process(InboundMessage::fromRaw($raw)))->toBe(InboxProcessor::REPLY)
            ->and($first->fresh()->status)->toBe(EmailStatus::Replied);
    });

    it('ignore un message sans rapport avec la prospection', function () {
        sequenceStarted();
        $raw = "From: newsletter@fournisseur.example\r\nSubject: Nos promotions\r\nMessage-ID: <promo@fournisseur.example>\r\n\r\nPromo !\r\n";

        expect(app(InboxProcessor::class)->process(InboundMessage::fromRaw($raw)))->toBe(InboxProcessor::IGNORED)
            ->and(Company::first()->status)->toBe(ProspectStatus::Contacted);
    });
});

describe('rebonds', function () {
    it('un rebond dur passe l’adresse en invalide, l’ajoute à la liste d’opposition et arrête la séquence', function () {
        $first = sequenceStarted();

        $kind = app(InboxProcessor::class)->process(inbound('hard-bounce', $first));

        expect($kind)->toBe(InboxProcessor::HARD_BOUNCE)
            ->and($first->fresh())->status->toBe(EmailStatus::Bounced)
            ->and(Contact::first())->verification->toBe(ContactVerification::Invalid)
            ->and(SuppressionEntry::firstWhere('email', 'contact@entreprise-1.fr')->reason)->toBe(SuppressionReason::HardBounce)
            ->and(Company::first()->status)->toBe(ProspectStatus::Bounced);

        workDay('2026-11-16');
        expect(sentSubjects())->toHaveCount(1);
    });

    it('un rebond temporaire est retenté une seule fois', function () {
        $first = sequenceStarted();

        expect(app(InboxProcessor::class)->process(inbound('soft-bounce', $first, 1)))->toBe(InboxProcessor::SOFT_BOUNCE)
            ->and($first->fresh())->status->toBe(EmailStatus::Queued)->retries->toBe(1);

        at('2026-11-09 08:10');
        $retried = app(EmailSender::class)->sendNext();
        expect($retried->is($first))->toBeTrue()->and($retried->status)->toBe(EmailStatus::Sent);
        expect(sentSubjects())->toHaveCount(2);

        // Second rebond temporaire : plus de nouvelle tentative, séquence arrêtée, sans liste d'opposition.
        app(InboxProcessor::class)->process(inbound('soft-bounce', $retried->fresh(), 2));

        expect($first->fresh()->status)->toBe(EmailStatus::Bounced)
            ->and(SuppressionEntry::count())->toBe(0)
            ->and(Contact::first()->verification)->toBe(ContactVerification::Valid);

        workDay('2026-11-16');
        expect(sentSubjects())->toHaveCount(2);
    });
});

describe('lecture de la boîte', function () {
    it('traite chaque message une seule fois, même relu', function () {
        $first = sequenceStarted();
        $raws = [inbound('auto-reply', $first)->raw, inbound('reply', $first)->raw];

        app()->instance(Mailbox::class, new class($raws) implements Mailbox
        {
            public function __construct(private array $raws) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function rawMessagesSince(CarbonInterface $since): iterable
            {
                return $this->raws;
            }
        });

        $counts = app()->call([new ReadInbox, 'handle']);
        app()->call([new ReadInbox, 'handle']);

        expect($counts)->toBe([InboxProcessor::AUTO_REPLY => 1, InboxProcessor::REPLY => 1])
            ->and(InboundRecord::count())->toBe(2)
            ->and(Company::first()->activities()->where('type', ActivityType::EmailReceived)->count())->toBe(1);
    });

    it('ne fait rien tant que l’IMAP n’est pas configuré', function () {
        config(['prospection.imap.host' => null]);

        $this->artisan('app:read-inbox')->expectsOutputToContain('IMAP non configuré')->assertSuccessful();
    });
});
