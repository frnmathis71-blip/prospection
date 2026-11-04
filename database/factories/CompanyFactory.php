<?php

namespace Database\Factories;

use App\Enums\DataSource;
use App\Enums\ProspectStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Entreprises FICTIVES de Bourgogne pour la démo et les tests.
 *
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /** Communes de la zone cible : [ville, code postal, département, latitude, longitude]. */
    private const CITIES = [
        ['Dijon', '21000', '21', 47.322047, 5.041480],
        ['Beaune', '21200', '21', 47.025780, 4.839790],
        ['Nevers', '58000', '58', 46.990896, 3.159000],
        ['Cosne-Cours-sur-Loire', '58200', '58', 47.411030, 2.925080],
        ['Mâcon', '71000', '71', 46.306884, 4.828731],
        ['Chalon-sur-Saône', '71100', '71', 46.780764, 4.853947],
        ['Le Creusot', '71200', '71', 46.807520, 4.416320],
        ['Auxerre', '89000', '89', 47.798202, 3.573781],
        ['Sens', '89100', '89', 48.197500, 3.283300],
    ];

    /** Codes NAF des secteurs ciblés : bâtiment, restauration/commerce, beauté, professions libérales. */
    private const NAF_CODES = [
        ['43.22A', 'Plomberie'], ['43.21A', 'Électricité'], ['43.32A', 'Menuiserie'], ['43.34Z', 'Peinture'],
        ['56.10A', 'Restaurant'], ['56.10C', 'Snack'], ['47.24Z', 'Boulangerie'], ['47.76Z', 'Fleuriste'],
        ['96.02A', 'Coiffure'], ['96.02B', 'Institut de beauté'],
        ['69.10Z', 'Cabinet juridique'], ['86.90E', 'Cabinet paramédical'],
    ];

    public function definition(): array
    {
        [$city, $postalCode, $department, $latitude, $longitude] = fake()->randomElement(self::CITIES);
        [$naf, $activity] = fake()->randomElement(self::NAF_CODES);

        return [
            'siren' => (string) fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'name' => $activity.' '.fake()->lastName(),
            'naf_code' => $naf,
            'address' => fake()->buildingNumber().' '.fake()->streetName(),
            'postal_code' => $postalCode,
            'city' => $city,
            'department' => $department,
            'latitude' => $latitude + fake()->randomFloat(4, -0.02, 0.02),
            'longitude' => $longitude + fake()->randomFloat(4, -0.02, 0.02),
            'phone' => fake()->boolean(80) ? '03'.fake()->numerify('########') : null,
            'headcount_range' => fake()->randomElement(['00', '01', '02', '03', '11']),
            'founded_on' => fake()->dateTimeBetween('-25 years', '-1 month'),
            'is_active' => true,
            'status' => ProspectStatus::New,
            'score' => fake()->numberBetween(0, 100),
            'source' => fake()->randomElement([DataSource::RechercheEntreprises, DataSource::CsvImport]),
        ];
    }

    public function status(ProspectStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
