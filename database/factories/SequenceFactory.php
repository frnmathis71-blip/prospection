<?php

namespace Database\Factories;

use App\Models\Sequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sequence>
 */
class SequenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Séquence standard J0 / J+4 / J+12',
        ];
    }
}
