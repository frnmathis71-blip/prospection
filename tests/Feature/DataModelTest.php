<?php

use App\Enums\ProspectStatus;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Email;
use App\Models\Search;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\SuppressionEntry;
use App\Models\Template;
use App\Models\Website;
use Illuminate\Database\UniqueConstraintViolationException;

// « migrate:fresh --seed » est testé dans tests/Database (hors transaction de test).

it('ne charge jamais les données de démo en production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(Company::count())->toBe(0);
});

it('ne crée que des adresses de démo sur le domaine réservé .example', function () {
    $this->seed();

    $emails = Contact::pluck('email')->merge(SuppressionEntry::whereNotNull('email')->pluck('email'));

    expect($emails)->not->toBeEmpty()
        ->each->toEndWith('.example');
});

describe('relations', function () {
    it('relie une entreprise à son site, ses contacts, ses emails, ses notes et sa recherche', function () {
        $search = Search::factory()->create();
        $company = Company::factory()->for($search)->create();
        $website = Website::factory()->for($company)->create();
        $contacts = Contact::factory()->count(2)->for($company)->create();
        $email = Email::factory()->for($contacts->first())->create();
        $activity = Activity::factory()->for($company)->create();

        expect($company->website->is($website))->toBeTrue()
            ->and($website->company->is($company))->toBeTrue()
            ->and($company->contacts)->toHaveCount(2)
            ->and($contacts->first()->company->is($company))->toBeTrue()
            ->and($company->emails->first()->is($email))->toBeTrue()
            ->and($company->activities->first()->is($activity))->toBeTrue()
            ->and($activity->company->is($company))->toBeTrue()
            ->and($company->search->is($search))->toBeTrue()
            ->and($search->companies->first()->is($company))->toBeTrue();
    });

    it('relie un email à son contact, sa campagne, son étape et son template', function () {
        $step = SequenceStep::factory()->create();
        $email = Email::factory()->create([
            'sequence_step_id' => $step->id,
            'template_id' => $step->template_id,
        ]);

        expect($email->contact->emails->first()->is($email))->toBeTrue()
            ->and($email->campaign->emails->first()->is($email))->toBeTrue()
            ->and($email->sequenceStep->is($step))->toBeTrue()
            ->and($step->emails->first()->is($email))->toBeTrue()
            ->and($email->template->is($step->template))->toBeTrue()
            ->and($step->template->emails->first()->is($email))->toBeTrue();
    });

    it('relie une séquence à ses étapes ordonnées, leurs templates et ses campagnes', function () {
        $sequence = Sequence::factory()->create();
        $template = Template::factory()->create();
        foreach ([3 => 12, 1 => 0, 2 => 4] as $position => $delay) {
            SequenceStep::factory()->for($sequence)->for($template)->create(['position' => $position, 'delay_days' => $delay]);
        }
        $campaign = Campaign::factory()->for($sequence)->create();

        expect($sequence->steps->pluck('delay_days')->all())->toBe([0, 4, 12])
            ->and($sequence->steps->first()->sequence->is($sequence))->toBeTrue()
            ->and($template->sequenceSteps)->toHaveCount(3)
            ->and($sequence->campaigns->first()->is($campaign))->toBeTrue()
            ->and($campaign->sequence->is($sequence))->toBeTrue()
            ->and($campaign->search->campaigns->first()->is($campaign))->toBeTrue();
    });

    it('supprime site, contacts, emails et notes avec l’entreprise', function () {
        $company = Company::factory()->create();
        Website::factory()->for($company)->create();
        Email::factory()->for(Contact::factory()->for($company))->create();
        Activity::factory()->for($company)->create();

        $company->delete();

        expect(Website::count())->toBe(0)
            ->and(Contact::count())->toBe(0)
            ->and(Email::count())->toBe(0)
            ->and(Activity::count())->toBe(0);
    });
});

it('refuse un SIREN en double', function () {
    Company::factory()->create(['siren' => '123456789']);

    Company::factory()->create(['siren' => '123 456 789']);
})->throws(UniqueConstraintViolationException::class);

it('accepte plusieurs entreprises sans SIREN (import CSV)', function () {
    Company::factory()->count(2)->create(['siren' => null]);

    expect(Company::whereNull('siren')->count())->toBe(2);
});

describe("liste d'opposition", function () {
    it('détecte une adresse quelle que soit la casse', function () {
        SuppressionEntry::factory()->create(['email' => 'Contact@Plombier-Martin.FR']);

        expect(SuppressionEntry::first()->email)->toBe('contact@plombier-martin.fr')
            ->and(SuppressionEntry::blocks('contact@plombier-martin.fr'))->toBeTrue()
            ->and(SuppressionEntry::blocks('CONTACT@PLOMBIER-MARTIN.FR'))->toBeTrue()
            ->and(SuppressionEntry::blocks('  Contact@plombier-martin.fr '))->toBeTrue()
            ->and(SuppressionEntry::blocks('devis@plombier-martin.fr'))->toBeFalse();
    });

    it('détecte toutes les adresses d’un domaine bloqué, quelle que soit la casse', function () {
        SuppressionEntry::factory()->domain('@Agence-Concurrente.FR')->create();

        expect(SuppressionEntry::blocks('Jean@AGENCE-concurrente.fr'))->toBeTrue()
            ->and(SuppressionEntry::blocks('jean@autre-agence.fr'))->toBeFalse();
    });

    it("signale un contact dont l'adresse est en opposition", function () {
        $contact = Contact::factory()->create(['email' => 'Info@Boulangerie.example']);
        SuppressionEntry::factory()->create(['email' => 'INFO@boulangerie.example']);

        expect($contact->email)->toBe('info@boulangerie.example')
            ->and($contact->isSuppressed())->toBeTrue();
    });

    it('refuse deux fois la même adresse, quelle que soit la casse', function () {
        SuppressionEntry::factory()->create(['email' => 'contact@exemple.fr']);
        SuppressionEntry::factory()->create(['email' => 'CONTACT@exemple.fr']);
    })->throws(UniqueConstraintViolationException::class);
});

describe('statuts des prospects', function () {
    it('accepte toutes les valeurs prévues', function (ProspectStatus $status) {
        $company = Company::factory()->create(['status' => $status]);

        expect($company->fresh()->status)->toBe($status);
    })->with(ProspectStatus::cases());

    it('refuse une valeur non prévue', function () {
        Company::factory()->create(['status' => 'presque_signe']);
    })->throws(ValueError::class);

    it('couvre exactement les statuts du cahier des charges', function () {
        expect(collect(ProspectStatus::cases())->map->getLabel()->all())->toBe([
            'Nouveau', 'Qualifié', 'Contacté', 'Relancé', 'A répondu', 'Devis envoyé',
            'Gagné', 'Perdu', 'Désinscrit', 'Rebond', 'Ne pas contacter',
        ]);
    });

    it('crée une entreprise au statut « Nouveau » par défaut', function () {
        $company = Company::create(['name' => 'Plomberie Test', 'source' => 'manual']);

        expect($company->status)->toBe(ProspectStatus::New);
    });
});
