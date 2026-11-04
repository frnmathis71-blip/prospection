<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Search;
use App\Models\Sequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Campagne pilote',
            'search_id' => Search::factory(),
            'sequence_id' => Sequence::factory(),
            'daily_quota' => 10,
            'hourly_quota' => 3,
            'status' => CampaignStatus::Draft,
        ];
    }
}
