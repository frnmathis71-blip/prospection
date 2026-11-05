<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Enums\DataSource;
use App\Models\Company;
use App\Services\ApiUnavailableException;
use App\Services\ContactRecorder;
use App\Services\DomainGuesser;
use App\Services\OverpassClient;
use App\Support\Phone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;

/**
 * Enrichissement gratuit d'une entreprise (mission M4) :
 * 1. OpenStreetMap (Overpass) : site, téléphone, email et signaux d'activité (horaires, réseaux sociaux) ;
 * 2. sans site trouvé : devinette de domaine (nomentreprise.fr / .com, vérifiée).
 *
 * Les données existantes ne sont jamais écrasées. Le débit est limité (RateLimiter « enrichment »).
 */
class EnrichCompany implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    /** File dédiée, traitée après « default » : un gros lot d'enrichissements ne bloque ni import ni recherche. */
    public const QUEUE = 'enrichment';

    public function __construct(public Company $company)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('enrichment')];
    }

    /** Les remises en file (limite de débit, API indisponible) sont permises pendant 24 heures. */
    public function retryUntil(): Carbon
    {
        return now()->addDay();
    }

    public function handle(OverpassClient $overpass, DomainGuesser $guesser): void
    {
        $company = $this->company->fresh();

        if ($company === null) {
            return;
        }

        try {
            if ($company->latitude !== null && $company->longitude !== null) {
                $tags = $overpass->findPlace($company->name, (float) $company->latitude, (float) $company->longitude);

                if ($tags !== null) {
                    $this->applyOpenStreetMap($company, OverpassClient::extract($tags));
                }
            }

            if (! $company->website()->exists() && ($url = $guesser->guess($company))) {
                $company->website()->create(['url' => $url]);
                $company->activities()->create([
                    'type' => ActivityType::Note,
                    'content' => "Site trouvé par devinette de domaine : {$url}.",
                    'occurred_at' => now(),
                ]);
            }
        } catch (ApiUnavailableException $exception) {
            $this->release($exception->retryAfterSeconds);

            return;
        }

        $company->update(['enriched_at' => now()]);

        // M5 : le site (trouvé ou non) est ensuite analysé.
        AnalyzeWebsite::dispatch($company);
    }

    /**
     * @param  array{phone: ?string, website: ?string, email: ?string, signals: array<string, mixed>}  $data
     */
    private function applyOpenStreetMap(Company $company, array $data): void
    {
        if (blank($company->phone) && filled($data['phone'])) {
            $company->phone = Phone::pickBusiness($data['phone']);
        }

        $company->osm_signals = $data['signals'];
        $company->save();

        if (filled($data['website']) && ! $company->website()->exists()) {
            $url = preg_match('#^https?://#i', $data['website']) ? $data['website'] : 'http://'.$data['website'];
            $company->website()->create(['url' => $url]);
        }

        if (filled($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            ContactRecorder::record($company, $data['email'], DataSource::OpenStreetMap);
        }

        $company->activities()->create([
            'type' => ActivityType::Note,
            'content' => 'Complétée avec OpenStreetMap (« '.($data['signals']['osm_name'] ?? $company->name).' », © contributeurs OpenStreetMap).',
            'occurred_at' => now(),
        ]);
    }
}
