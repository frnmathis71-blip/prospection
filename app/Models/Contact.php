<?php

namespace App\Models;

use App\Enums\ContactType;
use App\Enums\ContactVerification;
use App\Enums\DataSource;
use App\Jobs\VerifyContact;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Adresse email d'une entreprise, avec sa source et sa date de collecte (table contacts).
 */
#[Fillable(['company_id', 'email', 'type', 'source', 'source_url', 'collected_at', 'verification', 'verified_at', 'verification_note', 'reviewed_at'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $attributes = [
        'type' => 'generic',
        'verification' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'source' => DataSource::class,
            'verification' => ContactVerification::class,
            'collected_at' => 'datetime',
            'verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * M7 : toute nouvelle adresse est vérifiée (syntaxe + MX) en tâche de fond, quelle que soit
     * sa source (site, OpenStreetMap, import, saisie manuelle).
     */
    protected static function booted(): void
    {
        static::created(fn (Contact $contact) => VerifyContact::dispatch($contact));
    }

    /**
     * Adresse personnelle (messagerie grand public) pas encore validée à la main : à revoir avant
     * toute prospection (cahier des charges, section RGPD).
     */
    public function needsReview(): bool
    {
        return $this->type === ContactType::Personal && $this->reviewed_at === null;
    }

    /**
     * Adresse toujours stockée en minuscules et sans espaces autour.
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /**
     * Vrai si l'adresse ou son domaine figure sur la liste d'opposition.
     */
    public function isSuppressed(): bool
    {
        return SuppressionEntry::blocks($this->email);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Email, $this> */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }
}
