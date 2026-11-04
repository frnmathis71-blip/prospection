<?php

namespace App\Models;

use Database\Factories\SequenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Séquence de relance (table sequences), ex. J0 / J+4 / J+12.
 */
#[Fillable(['name'])]
class Sequence extends Model
{
    /** @use HasFactory<SequenceFactory> */
    use HasFactory;

    /** @return HasMany<SequenceStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class)->orderBy('position');
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
