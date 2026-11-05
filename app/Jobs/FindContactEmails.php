<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Enums\DataSource;
use App\Models\Company;
use App\Services\ContactRecorder;
use App\Services\EmailFinder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;

/**
 * Cherche les emails publiés sur le site d'une entreprise (mission M7) : accueil, contact,
 * mentions légales. Lancé après l'analyse du site (M5), depuis Filament ou par
 * `php artisan app:find-emails`. Chaque adresse trouvée garde sa source, sa page et sa date
 * de collecte, puis est vérifiée (syntaxe + MX) par VerifyContact.
 */
class FindContactEmails implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public function __construct(public Company $company)
    {
        $this->onQueue(EnrichCompany::QUEUE);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('email_search')];
    }

    public function retryUntil(): Carbon
    {
        return now()->addDay();
    }

    public function handle(EmailFinder $finder): void
    {
        $company = $this->company->fresh();

        // Minimisation : rien n'est collecté pour une entreprise fermée ou à ne pas contacter.
        if ($company === null || ! $company->is_active || $company->status->isBlocking() || $company->website === null) {
            return;
        }

        $result = $finder->find($company->website);
        $new = [];

        foreach ($result['emails'] as ['email' => $email, 'page' => $page]) {
            $existed = $company->contacts()->where('email', $email)->exists();
            ContactRecorder::record($company, $email, DataSource::Website, $page);

            if (! $existed) {
                $new[] = $email;
            }
        }

        $company->update(['emails_searched_at' => now()]);

        if ($result['visited'] !== []) {
            $company->activities()->create([
                'type' => ActivityType::Note,
                'content' => $new
                    ? 'Email(s) trouvé(s) sur le site : '.implode(', ', $new).'.'
                    : 'Aucun nouvel email publié sur le site ('.count($result['visited']).' page(s) consultée(s)).',
                'occurred_at' => now(),
            ]);
        }
    }
}
