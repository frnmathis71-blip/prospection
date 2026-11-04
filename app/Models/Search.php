<?php

namespace App\Models;

use Database\Factories\SearchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recherche d'entreprises sauvegardée et relançable (table searches).
 */
#[Fillable(['name', 'criteria', 'recurrence', 'last_run_at'])]
class Search extends Model
{
    /** @use HasFactory<SearchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'last_run_at' => 'datetime',
        ];
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
