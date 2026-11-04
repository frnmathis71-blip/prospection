<?php

namespace App\Models;

use App\Enums\DataSource;
use App\Enums\ProspectStatus;
use App\Support\Phone;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Entreprise prospectée (table companies).
 */
#[Fillable([
    'siren', 'name', 'naf_code', 'address', 'postal_code', 'city', 'department',
    'latitude', 'longitude', 'phone', 'headcount_range', 'founded_on', 'is_active',
    'status', 'score', 'next_action_on', 'quote_amount', 'source', 'search_id',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'new',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'status' => ProspectStatus::class,
            'source' => DataSource::class,
            'founded_on' => 'date',
            'next_action_on' => 'date',
            'is_active' => 'boolean',
            'score' => 'integer',
            'quote_amount' => 'decimal:2',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    /**
     * Le SIREN est stocké sans espaces (« 123 456 789 » → « 123456789 »).
     */
    protected function siren(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : preg_replace('/\s+/', '', $value));
    }

    /**
     * Le téléphone est stocké dans un format unique (« 0380123456 ») pour pouvoir être comparé.
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::normalize($value));
    }

    /** @return HasOne<Website, $this> */
    public function website(): HasOne
    {
        return $this->hasOne(Website::class);
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /** @return HasManyThrough<Email, Contact, $this> */
    public function emails(): HasManyThrough
    {
        return $this->hasManyThrough(Email::class, Contact::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('occurred_at');
    }

    /** @return BelongsTo<Search, $this> */
    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }
}
