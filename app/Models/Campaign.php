<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campagne d'envoi (table campaigns). Toujours créée en brouillon :
 * seul l'utilisateur déclenche un envoi réel.
 */
#[Fillable(['name', 'search_id', 'sequence_id', 'daily_quota', 'hourly_quota', 'status', 'started_at'])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'daily_quota' => 'integer',
            'hourly_quota' => 'integer',
            'started_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Search, $this> */
    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    /** @return BelongsTo<Sequence, $this> */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    /** @return HasMany<Email, $this> */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }
}
