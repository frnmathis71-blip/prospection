<?php

namespace Database\Factories;

use App\Enums\SuppressionReason;
use App\Models\SuppressionEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SuppressionEntry>
 */
class SuppressionEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email' => 'contact@'.Str::slug(fake()->unique()->words(2, true)).'.example',
            'domain' => null,
            'reason' => SuppressionReason::Unsubscribed,
        ];
    }

    public function domain(string $domain): static
    {
        return $this->state(fn () => ['email' => null, 'domain' => $domain, 'reason' => SuppressionReason::Manual]);
    }
}
