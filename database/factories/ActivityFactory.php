<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => ActivityType::Note,
            'content' => fake()->randomElement([
                'Rappeler après les vacances.',
                'Intéressé, attend un devis pour un site vitrine.',
                'Déjà un prestataire, ne pas relancer avant six mois.',
            ]),
            'occurred_at' => fake()->dateTimeBetween('-2 months'),
        ];
    }
}
