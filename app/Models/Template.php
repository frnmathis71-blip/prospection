<?php

namespace App\Models;

use App\Enums\TemplateAngle;
use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle d'email (table templates).
 */
#[Fillable(['name', 'angle', 'sector', 'variant', 'subject', 'body', 'cta_label'])]
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'angle' => TemplateAngle::class,
        ];
    }

    /** @return HasMany<SequenceStep, $this> */
    public function sequenceSteps(): HasMany
    {
        return $this->hasMany(SequenceStep::class);
    }

    /** @return HasMany<Email, $this> */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }
}
