<?php

namespace Database\Factories;

use App\Enums\WebsiteProblem;
use App\Enums\WebsiteState;
use App\Models\Company;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Website>
 */
class WebsiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'url' => 'https://'.Str::slug(fake()->unique()->words(2, true)).'.example',
            'state' => WebsiteState::Active,
            'https' => true,
            'responsive' => true,
            'pagespeed' => fake()->numberBetween(60, 100),
            'copyright_year' => (int) now()->format('Y'),
            'technologies' => [],
            'problems' => [],
            'analyzed_at' => now(),
        ];
    }

    /** Site daté : HTTP, pas responsive, copyright ancien. */
    public function outdated(): static
    {
        return $this->state(fn () => [
            'url' => 'http://'.Str::slug(fake()->unique()->words(2, true)).'.example',
            'state' => WebsiteState::Outdated,
            'https' => false,
            'responsive' => false,
            'pagespeed' => fake()->numberBetween(10, 45),
            'copyright_year' => fake()->numberBetween(2008, 2016),
            'technologies' => ['jQuery' => '1.11.3', 'WordPress' => '4.9.8'],
            'problems' => [
                WebsiteProblem::NoHttps->value,
                WebsiteProblem::NotResponsive->value,
                WebsiteProblem::SlowMobile->value,
                WebsiteProblem::OldCopyright->value,
                WebsiteProblem::OutdatedCms->value,
                WebsiteProblem::OldJquery->value,
            ],
        ]);
    }

    /** Aucun site trouvé. */
    public function none(): static
    {
        return $this->state(fn () => [
            'url' => null,
            'state' => WebsiteState::None,
            'https' => null,
            'responsive' => null,
            'pagespeed' => null,
            'copyright_year' => null,
            'technologies' => null,
            'problems' => null,
        ]);
    }
}
