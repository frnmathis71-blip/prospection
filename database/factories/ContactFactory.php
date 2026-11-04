<?php

namespace Database\Factories;

use App\Enums\ContactType;
use App\Enums\ContactVerification;
use App\Enums\DataSource;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Adresses FICTIVES en .example (domaine réservé, aucun email ne peut y arriver).
 *
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'email' => 'contact@'.Str::slug(fake()->unique()->words(2, true)).'.example',
            'type' => ContactType::Generic,
            'source' => DataSource::Website,
            'collected_at' => fake()->dateTimeBetween('-6 months'),
            'verification' => ContactVerification::Valid,
            'verified_at' => now(),
        ];
    }

    public function nominative(): static
    {
        return $this->state(fn () => [
            'email' => Str::slug(fake()->firstName()).'@'.Str::slug(fake()->unique()->words(2, true)).'.example',
            'type' => ContactType::Nominative,
        ]);
    }
}
