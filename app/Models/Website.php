<?php

namespace App\Models;

use App\Enums\WebsiteState;
use Database\Factories\WebsiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Site web d'une entreprise et résultat de son analyse (table websites).
 */
#[Fillable([
    'company_id', 'url', 'state', 'https', 'responsive', 'pagespeed',
    'copyright_year', 'technologies', 'problems', 'screenshot_path', 'analyzed_at',
])]
class Website extends Model
{
    /** @use HasFactory<WebsiteFactory> */
    use HasFactory;

    protected $attributes = [
        'state' => 'unknown',
    ];

    protected function casts(): array
    {
        return [
            'state' => WebsiteState::class,
            'https' => 'boolean',
            'responsive' => 'boolean',
            'pagespeed' => 'integer',
            'copyright_year' => 'integer',
            'technologies' => 'array',
            'problems' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
