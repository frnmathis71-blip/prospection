<?php

namespace Database\Factories;

use App\Enums\TemplateAngle;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Sans site — présentation',
            'angle' => TemplateAngle::NoWebsite,
            'sector' => null,
            'variant' => 'A',
            'subject' => '{nom_entreprise} : vos clients vous cherchent sur Google',
            'body' => "Bonjour,\n\nJe n'ai pas trouvé de site pour {nom_entreprise} à {ville}…",
        ];
    }

    public function outdated(): static
    {
        return $this->state(fn () => [
            'name' => 'Site obsolète — refonte',
            'angle' => TemplateAngle::OutdatedWebsite,
            'subject' => 'Votre site {nom_entreprise} sur mobile',
            'body' => "Bonjour,\n\nEn visitant votre site, j'ai remarqué : {problemes_detectes}…",
        ]);
    }
}
