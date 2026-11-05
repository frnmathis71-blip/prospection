<?php

use App\Enums\CampaignStatus;
use App\Enums\ContactVerification;
use App\Enums\EmailStatus;
use App\Enums\ProspectStatus;
use App\Enums\SuppressionReason;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Filament\Widgets\FunnelWidget;
use App\Filament\Widgets\RatesWidget;
use App\Filament\Widgets\SendingHealthWidget;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Email;
use App\Models\SuppressionEntry;
use App\Models\Template;
use App\Models\User;
use App\Services\CircuitBreaker;
use App\Services\EmailSender;
use App\Services\ProspectingStats;
use App\Services\TemplateRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
    Mail::fake();
});

/**
 * Jeu de données connu :
 * C1 contactée (A) → réponse → signée 1 500 € · C2 contactée (A) sans réponse · C3 contactée (B) → réponse → devis 900 €
 * C4 contactée (B) → rebond · C5 contactée (A) → réponse · C6 en file seulement · C7 adresse invalide seulement
 * C8 adresse à vérifier · C9 signée 400 € par téléphone (sans email) · C10 sans adresse.
 */
function knownDataset(): array
{
    $campaign = Campaign::factory()->create(['search_id' => null]);
    $templateA = Template::factory()->create(['name' => 'J0 — Sans site', 'variant' => 'A']);
    $templateB = Template::factory()->create(['name' => 'J0 — Sans site', 'variant' => 'B']);

    $make = function (int $i, array $company, ?ContactVerification $verification = ContactVerification::Valid) {
        $model = Company::factory()->create($company + ['name' => "C{$i}", 'status' => ProspectStatus::New, 'quote_amount' => null]);
        $contact = $verification ? Contact::factory()->for($model)->create(['email' => "contact@c{$i}.fr", 'verification' => $verification]) : null;

        return [$model, $contact];
    };

    $email = fn (array $pair, Template $template, EmailStatus $status) => Email::factory()->create([
        'campaign_id' => $campaign->id, 'company_id' => $pair[0]->id, 'contact_id' => $pair[1]->id, 'template_id' => $template->id,
        'status' => $status, 'sent_at' => $status === EmailStatus::Queued ? null : now()->subDays(2),
    ]);

    $c = [];
    $c[1] = $make(1, ['department' => '71', 'naf_code' => '43.22A', 'status' => ProspectStatus::Won, 'quote_amount' => 1500]);
    $c[2] = $make(2, ['department' => '71', 'naf_code' => '43.22A', 'status' => ProspectStatus::Contacted]);
    $c[3] = $make(3, ['department' => '21', 'naf_code' => '56.10A', 'status' => ProspectStatus::QuoteSent, 'quote_amount' => 900]);
    $c[4] = $make(4, ['department' => '89', 'naf_code' => '56.10A', 'status' => ProspectStatus::Bounced]);
    $c[5] = $make(5, ['department' => '89', 'naf_code' => '96.02A', 'status' => ProspectStatus::Replied]);
    $c[6] = $make(6, ['department' => '71']);
    $c[7] = $make(7, [], ContactVerification::Invalid);
    $c[8] = $make(8, [], ContactVerification::Pending);
    $c[9] = $make(9, ['status' => ProspectStatus::Won, 'quote_amount' => 400], null);
    $c[10] = $make(10, [], null);

    $email($c[1], $templateA, EmailStatus::Replied);
    $email($c[2], $templateA, EmailStatus::Sent);
    $email($c[3], $templateB, EmailStatus::Replied);
    $email($c[4], $templateB, EmailStatus::Bounced);
    $email($c[5], $templateA, EmailStatus::Replied);
    $email($c[6], $templateA, EmailStatus::Queued);

    return compact('campaign', 'templateA', 'templateB');
}

describe('entonnoir et taux', function () {
    it('donne le chiffre exact de chaque étape de l’entonnoir', function () {
        knownDataset();

        expect(app(ProspectingStats::class)->funnel())->toBe([
            'found' => 10,
            'with_email' => 7,   // C1-C6 et C8 (l'adresse invalide de C7 ne compte pas)
            'contacted' => 5,    // C1-C5 (C6 seulement en file)
            'replied' => 3,      // C1, C3, C5
            'quoted' => 2,       // C1 (signée), C3
            'won' => 1,          // C1 (C9 signée par téléphone n'est pas dans l'entonnoir email)
            'revenue' => 1900.0, // 1 500 + 400 : tout le CA signé grâce à l'outil
        ]);
    });

    it('calcule le taux de réponse par template, variantes A/B séparées', function () {
        knownDataset();

        expect(app(ProspectingStats::class)->byTemplate()->map(fn ($row) => [$row['label'], $row['sent'], $row['replied'], $row['reply_rate']])->all())->toBe([
            ['J0 — Sans site (variante A)', 3, 2, 66.7],
            ['J0 — Sans site (variante B)', 2, 1, 50.0],
        ]);
    });

    it('calcule les taux de réponse et de conversion par zone et par secteur', function () {
        knownDataset();
        $stats = app(ProspectingStats::class);

        expect($stats->byGroup('zone')->keyBy('label')->map(fn ($row) => [$row['sent'], $row['replied'], $row['won'], $row['reply_rate'], $row['conversion_rate']])->all())->toBe([
            '71 — Saône-et-Loire' => [2, 1, 1, 50.0, 50.0],
            '89 — Yonne' => [2, 1, 0, 50.0, 0.0],
            "21 — Côte-d'Or" => [1, 1, 0, 100.0, 0.0],
        ]);

        expect($stats->byGroup('sector')->keyBy('label')->map(fn ($row) => [$row['sent'], $row['replied']])->all())->toBe([
            'Artisans du bâtiment' => [2, 1],
            'Restauration et commerces' => [2, 1],
            'Beauté et bien-être' => [1, 1],
        ]);
    });

    it('affiche les widgets du tableau de bord', function () {
        $this->actingAs(User::factory()->withAppAuthentication()->create());
        knownDataset();

        Livewire::test(FunnelWidget::class)->assertSee('Entreprises trouvées')->assertSee('60 % des contactées')->assertSee('1 900 €');
        Livewire::test(SendingHealthWidget::class)->assertSee('Rebonds (7 jours)')->assertSee('20 %')->assertSee('Armé');
        app(CircuitBreaker::class)->check(); // 1 rebond sur 5 : au-delà du seuil
        Livewire::test(SendingHealthWidget::class)->assertSee('Déclenché');
        Livewire::test(RatesWidget::class)->assertSee('66,7 %')->call('setGroup', 'zone')->assertSee('Saône-et-Loire');

        $this->get('/admin')->assertOk()->assertSee('Entonnoir de prospection');
    });
});

describe('coupe-circuit', function () {
    /** $count emails partis il y a $daysAgo jours, dont $bounced en rebond. */
    function sentEmails(Campaign $campaign, int $count, int $bounced = 0, int $daysAgo = 1): void
    {
        $contact = Contact::factory()->create();

        foreach (range(1, $count) as $i) {
            Email::factory()->create([
                'campaign_id' => $campaign->id, 'contact_id' => $contact->id,
                'status' => $i <= $bounced ? EmailStatus::Bounced : EmailStatus::Sent,
                'sent_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    it('ne se déclenche pas à 3 % de rebonds, mais au-delà', function () {
        $campaign = Campaign::factory()->create(['search_id' => null, 'status' => CampaignStatus::Active]);
        sentEmails($campaign, 100, bounced: 3);

        expect(app(CircuitBreaker::class)->check())->toBeFalse()->and($campaign->fresh()->status)->toBe(CampaignStatus::Active);

        sentEmails($campaign, 1, bounced: 1); // 4 sur 101 = 4 %

        expect(app(CircuitBreaker::class)->check())->toBeTrue()->and($campaign->fresh()->status)->toBe(CampaignStatus::Paused);
    });

    it('se déclenche au-delà de 2 % de désinscriptions, met toutes les campagnes en pause et prévient l’utilisateur', function () {
        $user = User::factory()->withAppAuthentication()->create();
        $active = Campaign::factory()->count(2)->create(['search_id' => null, 'status' => CampaignStatus::Active]);
        $draft = Campaign::factory()->create(['search_id' => null, 'status' => CampaignStatus::Draft]);
        sentEmails($active[0], 100);
        foreach (['a', 'b', 'c'] as $letter) {
            SuppressionEntry::create(['email' => "{$letter}@desinscrit.fr", 'reason' => SuppressionReason::Unsubscribed]);
        }

        expect(app(CircuitBreaker::class)->check())->toBeTrue();

        expect($active->map(fn ($campaign) => $campaign->fresh()->status)->unique()->all())->toBe([CampaignStatus::Paused])
            ->and($draft->fresh()->status)->toBe(CampaignStatus::Draft)
            ->and($user->notifications()->first()->data['title'])->toBe('Coupe-circuit déclenché : envois en pause')
            ->and($user->notifications()->first()->data['body'])->toContain('Désinscriptions à 3 % (3 sur 100)');
    });

    it('ne compte que les 7 derniers jours', function () {
        $campaign = Campaign::factory()->create(['search_id' => null, 'status' => CampaignStatus::Active]);
        sentEmails($campaign, 10, bounced: 5, daysAgo: 8);
        sentEmails($campaign, 100, daysAgo: 1);

        expect(app(CircuitBreaker::class)->check())->toBeFalse();
    });

    it('bloque tout envoi tant qu’il est déclenché, puis ne compte que les incidents postérieurs au réarmement', function () {
        $this->actingAs(User::factory()->withAppAuthentication()->create());
        config(['prospection.sender' => ['name' => 'Camille', 'company' => 'Codenyr', 'address' => 'Chalon', 'siret' => '000', 'phone' => null, 'website' => 'https://codenyr.fr', 'privacy_url' => 'https://codenyr.fr/c']]);
        $campaign = Campaign::factory()->create(['search_id' => null, 'status' => CampaignStatus::Active]);
        sentEmails($campaign, 10, bounced: 2);
        $queued = Email::factory()->create(['campaign_id' => $campaign->id, 'status' => EmailStatus::Queued, 'sent_at' => null]);

        expect(app(EmailSender::class)->sendNext())->toBeNull();
        Mail::assertNothingSent();
        expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused);

        // Reprise refusée tant que le coupe-circuit n'est pas réarmé.
        Livewire::test(ViewCampaign::class, ['record' => $campaign->getKey()])->callAction('resume');
        expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused);

        Livewire::test(ListCampaigns::class)->callAction('resetCircuitBreaker');
        expect(app(CircuitBreaker::class)->isTripped())->toBeFalse()
            ->and(app(CircuitBreaker::class)->check())->toBeFalse(); // anciens rebonds ignorés

        Livewire::test(ViewCampaign::class, ['record' => $campaign->getKey()])->callAction('resume');
        expect($campaign->fresh()->status)->toBe(CampaignStatus::Active)
            ->and($queued->fresh()->status)->toBe(EmailStatus::Queued);
    });

    it('se déclenche dès la désinscription de trop', function () {
        $campaign = Campaign::factory()->create(['search_id' => null, 'status' => CampaignStatus::Active]);
        sentEmails($campaign, 20);

        $this->get(TemplateRenderer::unsubscribeUrl('a@desinscrit.fr'))->assertOk(); // 1 sur 20 = 5 %

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused);
    });
});
