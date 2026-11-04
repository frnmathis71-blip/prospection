<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Enums\DataSource;
use App\Enums\SearchStatus;
use App\Models\Company;
use App\Models\Search;
use App\Services\ApiUnavailableException;
use App\Services\BodaccClient;
use App\Services\RechercheEntreprisesClient;
use App\Services\SearchCriteria;
use App\Support\CompanyName;
use App\Support\WorkerHeartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Lance une recherche sauvegardée sur l'API Recherche d'entreprises (mission M4).
 *
 * - Parcourt toutes les pages de résultats (dans la limite de max_pages).
 * - Exclut les entreprises fermées, hors critère d'ancienneté ou en procédure collective (BODACC).
 * - N'ajoute que les entreprises inconnues : relancer une recherche n'ajoute que les nouvelles.
 * - Si une API est indisponible ou limite le débit, le job est remis en file plus tard (il ne plante pas).
 * - Chaque entreprise ajoutée est ensuite enrichie en tâche de fond (EnrichCompany).
 */
class RunCompanySearch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public Search $search) {}

    /** Les remises en file (API indisponible) sont permises pendant 12 heures. */
    public function retryUntil(): Carbon
    {
        return now()->addHours(12);
    }

    public function handle(RechercheEntreprisesClient $api, BodaccClient $bodacc): void
    {
        $criteria = $this->search->searchCriteria();

        if (! $criteria->hasZone() && $criteria->allNafCodes() === [] && $criteria->text === null) {
            $this->search->update(['status' => SearchStatus::Failed, 'last_error' => 'Aucun critère : choisissez au moins une zone ou un secteur.']);

            return;
        }

        $this->search->update(['status' => SearchStatus::Running, 'last_error' => null]);

        $stats = [
            'found' => 0, 'received' => 0, 'created' => 0, 'completed' => 0, 'already_known' => 0,
            'excluded_closed' => 0, 'excluded_age' => 0, 'excluded_procedure' => 0, 'pages' => 0, 'truncated' => false,
        ];
        $maxPages = (int) config('prospection.recherche_entreprises.max_pages', 40);
        $pause = (int) (1_000_000 / max(1, (int) config('prospection.recherche_entreprises.requests_per_second', 4)));

        try {
            $page = 1;

            do {
                WorkerHeartbeat::beat(); // job long : le tableau de bord voit que le worker travaille
                $data = $api->search($criteria, $page);
                $stats['found'] = $data['total_results'];
                $stats['pages'] = $page;

                $this->processPage($data['results'], $criteria, $bodacc, $stats);

                $lastPage = min($data['total_pages'], $maxPages);
                $stats['truncated'] = $data['total_pages'] > $maxPages;
                $this->search->update(['last_run_stats' => $stats]);

                if (++$page <= $lastPage) {
                    Sleep::usleep($pause); // rester sous la limite publiée de l'API
                }
            } while ($page <= $lastPage);
        } catch (ApiUnavailableException $exception) {
            $this->search->update([
                'status' => SearchStatus::Retrying,
                'last_error' => $exception->getMessage().' — nouvelle tentative dans '.$exception->retryAfterSeconds.' s.',
                'last_run_stats' => $stats,
            ]);

            $this->release($exception->retryAfterSeconds);

            return;
        }

        $this->search->update([
            'status' => SearchStatus::Done,
            'last_run_at' => now(),
            'last_run_stats' => $stats,
            'last_error' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->search->update([
            'status' => SearchStatus::Failed,
            'last_error' => $exception?->getMessage() ?? 'Échec inconnu',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  array<string, int|bool>  $stats
     */
    private function processPage(array $results, SearchCriteria $criteria, BodaccClient $bodacc, array &$stats): void
    {
        $sirens = array_column($results, 'siren');
        $known = Company::query()->whereIn('siren', $sirens)->pluck('siren')->flip();
        $candidates = [];

        foreach ($results as $result) {
            $stats['received']++;
            $siren = (string) ($result['siren'] ?? '');

            if ($siren === '' || $known->has($siren) || isset($candidates[$siren])) {
                $stats['already_known']++;

                continue;
            }

            $establishment = self::establishment($result);

            if (($result['etat_administratif'] ?? 'A') !== 'A' || $establishment === null) {
                $stats['excluded_closed']++;

                continue;
            }

            if (! $criteria->acceptsFoundedOn($result['date_creation'] ?? null)) {
                $stats['excluded_age']++;

                continue;
            }

            $candidates[$siren] = [$result, $establishment];
        }

        $withProcedure = array_flip($bodacc->sirensWithCollectiveProcedure(array_keys($candidates)));

        foreach ($candidates as $siren => [$result, $establishment]) {
            if (isset($withProcedure[$siren])) {
                $stats['excluded_procedure']++;

                continue;
            }

            $attributes = self::attributes($result, $establishment);

            // Entreprise déjà importée sans SIREN (CSV) : on la complète au lieu d'en créer une seconde.
            if ($existing = self::sameCompanyWithoutSiren($attributes)) {
                foreach ($attributes as $key => $value) {
                    if (blank($existing->getAttribute($key)) && filled($value)) {
                        $existing->setAttribute($key, $value);
                    }
                }

                $existing->save();
                $stats['completed']++;

                continue;
            }

            $company = Company::create($attributes + [
                'source' => DataSource::RechercheEntreprises,
                'search_id' => $this->search->id,
            ]);

            $company->activities()->create([
                'type' => ActivityType::Note,
                'content' => 'Ajoutée par la recherche « '.$this->search->name.' » (API Recherche d’entreprises).',
                'occurred_at' => now(),
            ]);

            EnrichCompany::dispatch($company);
            $stats['created']++;
        }
    }

    /**
     * Établissement à retenir : un établissement ouvert correspondant à la zone, sinon le siège s'il est ouvert.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private static function establishment(array $result): ?array
    {
        $isOpen = fn (?array $establishment) => $establishment !== null && ($establishment['etat_administratif'] ?? 'A') === 'A';

        $matching = collect($result['matching_etablissements'] ?? [])->first($isOpen);

        if ($matching) {
            return $matching;
        }

        return $isOpen($result['siege'] ?? null) ? $result['siege'] : null;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $establishment
     * @return array<string, mixed>
     */
    private static function attributes(array $result, array $establishment): array
    {
        $name = $result['nom_raison_sociale'] ?? $result['nom_complet'] ?? $result['siren'];

        return [
            'siren' => (string) $result['siren'],
            'name' => mb_convert_case(mb_strtolower(trim((string) $name)), MB_CASE_TITLE),
            'naf_code' => $result['activite_principale'] ?? null,
            'address' => self::street($establishment),
            'postal_code' => $establishment['code_postal'] ?? null,
            'city' => isset($establishment['libelle_commune']) ? mb_convert_case(mb_strtolower($establishment['libelle_commune']), MB_CASE_TITLE) : null,
            'department' => $establishment['departement'] ?? (isset($establishment['code_postal']) ? substr($establishment['code_postal'], 0, 2) : null),
            'latitude' => is_numeric($establishment['latitude'] ?? null) ? (float) $establishment['latitude'] : null,
            'longitude' => is_numeric($establishment['longitude'] ?? null) ? (float) $establishment['longitude'] : null,
            'headcount_range' => $result['tranche_effectif_salarie'] ?? null,
            'founded_on' => $result['date_creation'] ?? null,
            'is_active' => true,
        ];
    }

    /**
     * Adresse sans le code postal ni la commune (déjà dans leurs colonnes).
     *
     * @param  array<string, mixed>  $establishment
     */
    private static function street(array $establishment): ?string
    {
        $address = trim((string) ($establishment['adresse'] ?? ''));

        if ($address === '') {
            return null;
        }

        if (filled($establishment['code_postal'] ?? null)) {
            $address = trim(preg_replace('/\s*'.preg_quote($establishment['code_postal'], '/').'.*$/u', '', $address));
        }

        return mb_convert_case(mb_strtolower($address), MB_CASE_TITLE) ?: null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function sameCompanyWithoutSiren(array $attributes): ?Company
    {
        if (blank($attributes['postal_code'])) {
            return null;
        }

        return Company::query()
            ->whereNull('siren')
            ->where('postal_code', $attributes['postal_code'])
            ->get()
            ->first(fn (Company $company) => CompanyName::similarity($attributes['name'], $company->name) > 0
                && CompanyName::similarity($company->name, $attributes['name']) > 0);
    }
}
