<?php

namespace App\Models;

use App\Enums\SearchStatus;
use App\Services\SearchCriteria;
use Database\Factories\SearchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recherche d'entreprises sauvegardée et relançable (table searches).
 *
 * Récurrence : null (manuelle), « weekly » (chaque lundi) ou « monthly » (le 1er du mois).
 */
#[Fillable(['name', 'criteria', 'recurrence', 'last_run_at', 'status', 'last_run_stats', 'last_error'])]
class Search extends Model
{
    /** @use HasFactory<SearchFactory> */
    use HasFactory;

    public const RECURRENCES = [
        'weekly' => 'Chaque lundi',
        'monthly' => 'Le 1er de chaque mois',
    ];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'last_run_at' => 'datetime',
            'status' => SearchStatus::class,
            'last_run_stats' => 'array',
        ];
    }

    public function searchCriteria(): SearchCriteria
    {
        return SearchCriteria::fromArray($this->criteria ?? []);
    }

    /** @return HasMany<Company, $this> */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
