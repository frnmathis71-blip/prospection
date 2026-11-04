<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * Critères d'une recherche sauvegardée, et leur traduction en paramètres de l'API
 * Recherche d'entreprises.
 *
 * L'API ne filtre pas la date de création : ce filtre est appliqué par l'outil
 * sur chaque résultat (acceptsFoundedOn).
 */
final readonly class SearchCriteria
{
    /**
     * @param  list<string>  $departments  codes département (21, 71…)
     * @param  list<string>  $postalCodes  codes postaux (5 chiffres)
     * @param  list<string>  $communeCodes  codes commune INSEE (5 caractères)
     * @param  list<string>  $sectors  clés de config('prospection.sectors')
     * @param  list<string>  $nafCodes  codes NAF ajoutés à la main (43.22A…)
     * @param  list<string>  $headcountRanges  tranches d'effectif INSEE
     * @param  ?int  $maxAgeMonths  entreprises créées il y a au plus N mois (cible « création de site »)
     * @param  ?int  $minAgeYears  entreprises créées il y a au moins N années
     */
    public function __construct(
        public array $departments = [],
        public array $postalCodes = [],
        public array $communeCodes = [],
        public array $sectors = [],
        public array $nafCodes = [],
        public array $headcountRanges = [],
        public ?int $maxAgeMonths = null,
        public ?int $minAgeYears = null,
        public ?string $text = null,
    ) {}

    public static function fromArray(array $criteria): self
    {
        $list = fn (string $key) => array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            Arr::wrap($criteria[$key] ?? []),
        )));

        return new self(
            departments: $list('departments'),
            postalCodes: $list('postal_codes'),
            communeCodes: $list('commune_codes'),
            sectors: $list('sectors'),
            nafCodes: array_map('mb_strtoupper', $list('naf_codes')),
            headcountRanges: $list('headcount_ranges'),
            maxAgeMonths: filled($criteria['max_age_months'] ?? null) ? (int) $criteria['max_age_months'] : null,
            minAgeYears: filled($criteria['min_age_years'] ?? null) ? (int) $criteria['min_age_years'] : null,
            text: filled($criteria['text'] ?? null) ? trim($criteria['text']) : null,
        );
    }

    /**
     * Codes NAF des presets choisis + codes saisis à la main, sans doublon.
     *
     * @return list<string>
     */
    public function allNafCodes(): array
    {
        $fromSectors = collect($this->sectors)
            ->flatMap(fn (string $sector) => config("prospection.sectors.{$sector}.naf", []));

        return $fromSectors->merge($this->nafCodes)->unique()->values()->all();
    }

    public function hasZone(): bool
    {
        return $this->departments !== [] || $this->postalCodes !== [] || $this->communeCodes !== [];
    }

    /**
     * Paramètres de requête pour GET /search.
     *
     * @return array<string, string|int>
     */
    public function toQuery(int $page = 1): array
    {
        $query = [
            'q' => $this->text,
            'departement' => implode(',', $this->departments),
            'code_postal' => implode(',', $this->postalCodes),
            'code_commune' => implode(',', $this->communeCodes),
            'activite_principale' => implode(',', $this->allNafCodes()),
            'tranche_effectif_salarie' => implode(',', $this->headcountRanges),
            'etat_administratif' => 'A', // entreprises actives uniquement
            'minimal' => 'true',
            'include' => 'siege,complements,matching_etablissements',
            'per_page' => (int) config('prospection.recherche_entreprises.per_page', 25),
            'page' => $page,
        ];

        return array_filter($query, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Filtre d'ancienneté, appliqué localement (l'API ne le propose pas).
     */
    public function acceptsFoundedOn(?string $date, ?CarbonImmutable $today = null): bool
    {
        if ($this->maxAgeMonths === null && $this->minAgeYears === null) {
            return true;
        }

        if (blank($date)) {
            return false;
        }

        $today ??= CarbonImmutable::today();
        $founded = CarbonImmutable::parse($date);

        if ($this->maxAgeMonths !== null && $founded->lt($today->subMonths($this->maxAgeMonths))) {
            return false;
        }

        return ! ($this->minAgeYears !== null && $founded->gt($today->subYears($this->minAgeYears)));
    }

    /**
     * Résumé lisible pour la liste des recherches.
     */
    public function summary(): string
    {
        $parts = [];

        if ($this->departments) {
            $parts[] = 'Dép. '.implode(', ', $this->departments);
        }

        if ($this->postalCodes) {
            $parts[] = 'CP '.implode(', ', $this->postalCodes);
        }

        if ($this->communeCodes) {
            $parts[] = 'Communes '.implode(', ', $this->communeCodes);
        }

        foreach ($this->sectors as $sector) {
            $parts[] = config("prospection.sectors.{$sector}.label", $sector);
        }

        if ($this->nafCodes) {
            $parts[] = 'NAF '.implode(', ', $this->nafCodes);
        }

        if ($this->maxAgeMonths) {
            $parts[] = "créées depuis moins de {$this->maxAgeMonths} mois";
        }

        if ($this->minAgeYears) {
            $parts[] = "créées il y a plus de {$this->minAgeYears} ans";
        }

        return implode(' · ', $parts) ?: 'Aucun critère';
    }
}
