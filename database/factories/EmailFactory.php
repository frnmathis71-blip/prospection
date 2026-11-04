<?php

namespace Database\Factories;

use App\Enums\EmailStatus;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Email;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Historique FICTIF : ces lignes ne correspondent à aucun envoi réel.
 *
 * @extends Factory<Email>
 */
class EmailFactory extends Factory
{
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'campaign_id' => Campaign::factory(),
            'sequence_step_id' => null,
            'template_id' => null,
            'subject' => 'Votre présence sur Google',
            'status' => EmailStatus::Sent,
            'sent_at' => fake()->dateTimeBetween('-1 month'),
            'message_id' => '<'.fake()->unique()->uuid().'@codenyr-demo.example>',
        ];
    }
}
