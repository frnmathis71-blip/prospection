<?php

namespace App\Services;

use App\Enums\ContactType;
use App\Enums\ContactVerification;
use App\Enums\WebsiteProblem;
use App\Enums\WebsiteState;
use App\Models\Company;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Score de priorité d'un prospect, de 0 à 100 (mission M6).
 *
 * Points additionnés (site, joignabilité, profil, secteur) puis ramenés sur 100 : 100 = le
 * meilleur prospect possible avec les poids actuels. Les poids viennent de
 * config('prospection.scoring'), remplacés par ceux enregistrés dans « Réglages du score ».
 */
class CompanyScorer
{
    public const SETTING_KEY = 'scoring_weights';

    /** @var array<string, int>|null */
    private ?array $weights = null;

    /**
     * Poids en vigueur (défauts + réglages enregistrés).
     *
     * @return array<string, int>
     */
    public static function weights(): array
    {
        $defaults = config('prospection.scoring', []);
        $saved = (array) Setting::get(self::SETTING_KEY, []);

        return array_map('intval', array_merge($defaults, array_intersect_key($saved, $defaults)));
    }

    /**
     * Calcule et enregistre le score (et son détail) d'une entreprise.
     */
    public function refresh(Company $company): Company
    {
        $company->loadMissing(['website', 'contacts']);
        ['score' => $score, 'details' => $details] = $this->compute($company);

        $company->forceFill(['score' => $score, 'score_details' => $details, 'scored_at' => now()])->saveQuietly();

        return $company;
    }

    /**
     * @return array{score: int, details: list<array{label: string, points: int}>}
     */
    public function compute(Company $company, ?CarbonImmutable $today = null): array
    {
        $w = $this->weights ??= self::weights();
        $today ??= CarbonImmutable::today();
        $details = [];
        $add = function (string $label, int $points) use (&$details) {
            if ($points !== 0) {
                $details[] = ['label' => $label, 'points' => $points];
            }
        };

        // 1. Site : la vraie opportunité commerciale.
        $website = $company->website;
        // Sans fiche de site, l'entreprise n'a pas encore été analysée : on ne présume pas
        // « aucun site » (l'analyse crée la fiche, éventuellement à l'état « Aucun site »).
        $state = $website?->state ?? WebsiteState::Unknown;

        $websitePoints = match ($state) {
            WebsiteState::None => ['Aucun site', $w['website_none']],
            WebsiteState::SocialOnly => ['Réseau social seul', $w['website_social_only']],
            WebsiteState::Directory => ['Mini-site d’annuaire seulement', $w['website_directory']],
            WebsiteState::Offline => ['Site hors ligne', $w['website_offline']],
            WebsiteState::Parking => ['Page parking à la place du site', $w['website_parking']],
            WebsiteState::Unknown => ['Site pas encore analysé', $w['website_unknown']],
            WebsiteState::Outdated => ['Site obsolète', $w['website_outdated']],
            WebsiteState::Active => null,
        };

        $problemPoints = 0;

        if ($website && in_array($state, [WebsiteState::Outdated, WebsiteState::Active], true)) {
            foreach ($website->detectedProblems() as $problem) {
                $problemPoints += $this->problemWeight($problem, $w);
            }
        }

        $siteTotal = min($w['website_max'], ($websitePoints[1] ?? 0) + $problemPoints);

        if ($websitePoints !== null) {
            $add($websitePoints[0], min($websitePoints[1], $siteTotal));
        }

        if ($problemPoints > 0) {
            $add(count($website->detectedProblems()).' problème(s) détecté(s) sur le site', $siteTotal - min($websitePoints[1] ?? 0, $siteTotal));
        }

        // 2. Joignabilité.
        if (filled($company->phone)) {
            $add('Téléphone connu', $w['phone']);
        }

        $usable = $company->contacts->filter(fn ($contact) => $contact->verification !== ContactVerification::Invalid && ! $contact->isSuppressed());

        if ($usable->contains(fn ($contact) => $contact->type !== ContactType::Personal)) {
            $add('Email professionnel', $w['email_pro']);
        } elseif ($usable->isNotEmpty()) {
            $add('Email personnel seulement', $w['email_personal']);
        }

        $signals = $company->osm_signals ?? [];

        if (! empty($signals['opening_hours']) || ! empty($signals['social'])) {
            $add('Active sur OpenStreetMap (horaires, réseaux)', $w['osm_active']);
        }

        // 3. Profil : ancienneté et taille.
        if ($company->founded_on) {
            $founded = CarbonImmutable::parse($company->founded_on);

            if ($founded->gte($today->subMonths(6))) {
                $add('Créée il y a moins de 6 mois', $w['new_company']);
            } elseif ($founded->lte($today->subYears(2))) {
                $add('Installée depuis '.(int) $founded->diffInYears($today).' ans', $w['established']);
            }
        }

        match ($company->headcount_range) {
            '01', '02', '03' => $add('1 à 9 salariés', $w['headcount_small']),
            '00', 'NN' => $add('Sans salarié', $w['headcount_micro']),
            '11', '12' => $add('10 à 49 salariés', $w['headcount_medium']),
            default => null,
        };

        // 4. Secteur.
        if ($sector = self::sectorOf($company->naf_code)) {
            $add('Secteur : '.config("prospection.sectors.{$sector}.label"), $w["sector_{$sector}"] ?? 0);
        }

        $points = array_sum(array_column($details, 'points'));
        $max = $this->maxPoints($w);

        return [
            'score' => $max > 0 ? (int) max(0, min(100, round($points * 100 / $max))) : 0,
            'details' => $details,
        ];
    }

    /**
     * Total de points du meilleur prospect possible avec ces poids.
     *
     * @param  array<string, int>  $w
     */
    public function maxPoints(array $w): int
    {
        $site = min($w['website_max'], max(
            $w['website_none'], $w['website_social_only'], $w['website_directory'], $w['website_offline'],
            $w['website_parking'], $w['website_unknown'], $w['website_outdated'] + 3 * $w['problem_high'] + 6 * $w['problem_medium'],
        ));

        $sectors = collect(array_keys(config('prospection.sectors', [])))->map(fn ($key) => $w["sector_{$key}"] ?? 0);

        return $site
            + $w['phone'] + max($w['email_pro'], $w['email_personal']) + $w['osm_active']
            + max($w['new_company'], $w['established'])
            + max($w['headcount_small'], $w['headcount_micro'], $w['headcount_medium'])
            + (int) $sectors->max();
    }

    public static function sectorOf(?string $nafCode): ?string
    {
        if (blank($nafCode)) {
            return null;
        }

        foreach (config('prospection.sectors', []) as $key => $sector) {
            if (in_array(mb_strtoupper($nafCode), $sector['naf'], true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $w
     */
    private function problemWeight(WebsiteProblem $problem, array $w): int
    {
        return match ($problem->severity()) {
            'high' => $w['problem_high'],
            'medium' => $w['problem_medium'],
            default => $w['problem_low'],
        };
    }
}
