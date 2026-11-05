<?php

namespace App\Models;

use App\Enums\WebsiteProblem;
use App\Enums\WebsiteState;
use App\Support\Domain;
use Database\Factories\WebsiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Site web d'une entreprise et résultat de son analyse (table websites).
 *
 * « problems » contient des codes WebsiteProblem ; « technologies » une table
 * nom → version (« WordPress » => « 4.9.8 », « jQuery » => « 1.11.3 », « Flash » => null).
 */
#[Fillable([
    'company_id', 'url', 'state', 'https', 'responsive', 'pagespeed',
    'copyright_year', 'technologies', 'problems', 'screenshot_path', 'analyzed_at',
    'final_url', 'http_status', 'analysis_note',
])]
class Website extends Model
{
    /** @use HasFactory<WebsiteFactory> */
    use HasFactory;

    protected $attributes = [
        'state' => 'unknown',
    ];

    /**
     * Le domaine est toujours déduit de l'adresse (null pour un réseau social ou un annuaire).
     */
    protected static function booted(): void
    {
        static::saving(function (Website $website) {
            $website->domain = Domain::fromUrl($website->url);
        });
    }

    protected function casts(): array
    {
        return [
            'state' => WebsiteState::class,
            'https' => 'boolean',
            'responsive' => 'boolean',
            'pagespeed' => 'integer',
            'copyright_year' => 'integer',
            'http_status' => 'integer',
            'technologies' => 'array',
            'problems' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    /**
     * Problèmes détectés, du plus grave au moins grave.
     *
     * @return list<WebsiteProblem>
     */
    public function detectedProblems(): array
    {
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];

        return collect($this->problems ?? [])
            ->map(fn ($code) => is_string($code) ? WebsiteProblem::tryFrom($code) : null)
            ->filter()
            ->sortBy(fn (WebsiteProblem $problem) => $order[$problem->severity()])
            ->values()
            ->all();
    }

    /**
     * Problèmes en clair, réutilisables dans la fiche et dans les emails ({problemes_detectes}).
     *
     * @return list<string>
     */
    public function problemLabels(): array
    {
        $technologies = $this->technologies ?? [];
        $cms = collect(['WordPress', 'Joomla', 'Drupal', 'PrestaShop'])->first(fn ($name) => array_key_exists($name, $technologies));

        $details = [
            'pagespeed' => $this->pagespeed,
            'copyright_year' => $this->copyright_year,
            'jquery' => $technologies['jQuery'] ?? null,
            'cms' => $cms ? trim($cms.' '.($technologies[$cms] ?? '')) : null,
        ];

        $labels = array_map(fn (WebsiteProblem $problem) => $problem->label(array_filter($details)), $this->detectedProblems());

        // Données saisies avant M5 (texte libre) : affichées telles quelles.
        $legacy = collect($this->problems ?? [])->filter(fn ($value) => is_string($value) && WebsiteProblem::tryFrom($value) === null);

        return array_values(array_merge($labels, $legacy->all()));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
