<?php

namespace Database\Factories;

use App\Models\Search;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Search>
 */
class SearchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Plombiers 71',
            'criteria' => ['departments' => ['71'], 'naf_codes' => ['43.22A'], 'active_only' => true],
            'recurrence' => null, // jamais de relance automatique pour une recherche de démo
            'last_run_at' => null,
        ];
    }
}
