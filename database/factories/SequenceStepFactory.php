<?php

namespace Database\Factories;

use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SequenceStep>
 */
class SequenceStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sequence_id' => Sequence::factory(),
            'position' => 1,
            'delay_days' => 0,
            'template_id' => Template::factory(),
        ];
    }
}
