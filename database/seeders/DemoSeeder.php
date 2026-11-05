<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\CampaignStatus;
use App\Enums\ContactType;
use App\Enums\DataSource;
use App\Enums\EmailStatus;
use App\Enums\ProspectStatus;
use App\Enums\SuppressionReason;
use App\Jobs\RecalculateScores;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Email;
use App\Models\Search;
use App\Models\Sequence;
use App\Models\SuppressionEntry;
use App\Models\Template;
use App\Models\Website;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration FICTIVES (entreprises inventées, adresses en .example).
 * Jamais exécuté en production (voir DatabaseSeeder).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $search = Search::factory()->create();

        // Modèles : un par angle, plus les deux relances de la séquence.
        $noWebsite = Template::factory()->create();
        $outdated = Template::factory()->outdated()->create();
        $followUp = Template::factory()->create([
            'name' => 'Relance courte',
            'variant' => null,
            'subject' => 'Re : votre présence en ligne',
            'body' => "Bonjour,\n\nJe me permets de revenir vers vous…",
        ]);
        $lastCall = Template::factory()->create([
            'name' => 'Dernière relance — exemple de réalisation',
            'variant' => null,
            'subject' => 'Un exemple de site pour {secteur}',
            'body' => "Bonjour,\n\nPour vous donner une idée, voici un site réalisé pour un {secteur}…",
        ]);

        $sequence = Sequence::factory()->create();
        foreach ([[1, 0, $noWebsite], [2, 4, $followUp], [3, 12, $lastCall]] as [$position, $delay, $template]) {
            $sequence->steps()->create(['position' => $position, 'delay_days' => $delay, 'template_id' => $template->id]);
        }

        // 15 entreprises sans site (peu ont un email trouvable).
        Company::factory()->count(15)->for($search)->create()->each(function (Company $company) {
            Website::factory()->none()->for($company)->create();

            if (fake()->boolean(25)) {
                Contact::factory()->for($company)->create(['source' => DataSource::OpenStreetMap]);
            }
        });

        // 15 entreprises au site obsolète (l'email est presque toujours sur le site).
        Company::factory()->count(15)->create()->each(function (Company $company) {
            Website::factory()->outdated()->for($company)->create();
            Contact::factory()->for($company)->create();
        });

        // 10 entreprises au site récent (prospects peu prioritaires).
        Company::factory()->count(10)->create(['score' => fake()->numberBetween(0, 25)])->each(function (Company $company) {
            Website::factory()->for($company)->create();
            Contact::factory()->nominative()->for($company)->create();
        });

        // Une adresse personnelle à revoir à la main.
        Contact::factory()->for(Company::query()->inRandomOrder()->first())->create([
            'email' => 'dupont.artisan@gmail.example',
            'type' => ContactType::Personal,
            'source' => DataSource::Manual,
        ]);

        // Liste d'opposition : une adresse désinscrite (entreprise passée en « Désinscrit »),
        // un rebond dur et un domaine entier bloqué.
        $unsubscribed = Contact::query()->inRandomOrder()->first();
        SuppressionEntry::factory()->create(['email' => $unsubscribed->email, 'reason' => SuppressionReason::Unsubscribed]);
        $unsubscribed->company->update(['status' => ProspectStatus::Unsubscribed]);
        SuppressionEntry::factory()->create(['reason' => SuppressionReason::HardBounce]);
        SuppressionEntry::factory()->domain('concurrent-agence-web.example')->create();

        // Une campagne terminée avec son historique, et une campagne en brouillon.
        $past = Campaign::factory()->for($search)->for($sequence)->create([
            'name' => 'Démo — plombiers 71 (terminée)',
            'status' => CampaignStatus::Completed,
            'started_at' => now()->subMonth(),
        ]);
        Campaign::factory()->for($search)->for($sequence)->create(['name' => 'Démo — brouillon']);

        $firstStep = $sequence->steps->first();
        Contact::query()->with('company')->inRandomOrder()->limit(8)->get()->each(
            function (Contact $contact, int $index) use ($past, $firstStep) {
                $status = [EmailStatus::Sent, EmailStatus::Sent, EmailStatus::Replied, EmailStatus::Bounced][$index % 4];

                Email::factory()->for($contact)->for($past)->create([
                    'sequence_step_id' => $firstStep->id,
                    'template_id' => $firstStep->template_id,
                    'status' => $status,
                ]);

                if ($contact->company->status === ProspectStatus::New) {
                    $contact->company->update(['status' => match ($status) {
                        EmailStatus::Replied => ProspectStatus::Replied,
                        EmailStatus::Bounced => ProspectStatus::Bounced,
                        default => ProspectStatus::Contacted,
                    }]);
                }
            }
        );

        // Quelques notes libres.
        Company::query()->inRandomOrder()->limit(6)->get()->each(
            fn (Company $company) => Activity::factory()->for($company)->create()
        );
        Activity::factory()->for(Company::query()->first())->create([
            'type' => ActivityType::Call,
            'content' => 'Appel : rappeler la semaine prochaine.',
        ]);

        // M6 : scores calculés avec les vrais critères (et non au hasard).
        RecalculateScores::dispatchSync();
    }
}
