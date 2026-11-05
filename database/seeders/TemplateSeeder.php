<?php

namespace Database\Seeders;

use App\Enums\TemplateAngle;
use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * Templates de départ (mission M8), à relire et adapter dans Filament.
 * Sans risque en production : un template déjà présent (même nom) n'est jamais modifié.
 *
 *     php artisan db:seed --class=TemplateSeeder
 */
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::templates() as $template) {
            Template::query()->firstOrCreate(['name' => $template['name']], $template);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function templates(): array
    {
        $signature = "Bonne journée,\\\n{expediteur}";
        $templates = [
            [
                'name' => 'J0 — Sans site',
                'angle' => TemplateAngle::NoWebsite,
                'variant' => 'A',
                'subject' => '{nom_entreprise} : vos clients vous cherchent sur Google',
                'body' => <<<MD
                Bonjour {prenom_dirigeant|Madame, Monsieur},

                Je m’appelle {expediteur}, je crée des sites internet pour les {secteur|entreprises} de Bourgogne.

                En cherchant {nom_entreprise} à {ville}, je n’ai pas trouvé de site internet à votre nom. Aujourd’hui, la plupart des clients commencent par une recherche sur Google, souvent depuis leur téléphone : sans site, ils tombent plus facilement sur un concurrent.

                Je vous propose un site vitrine simple et clair, adapté au mobile, avec vos prestations, vos photos et vos coordonnées. Le devis est gratuit et sans engagement.

                Si l’idée vous intéresse, il suffit de répondre à cet email. Vous pouvez aussi voir quelques réalisations ci-dessous.

                {$signature}
                MD,
            ],
            [
                'name' => 'J0 — Site obsolète',
                'angle' => TemplateAngle::OutdatedWebsite,
                'variant' => 'A',
                'subject' => 'Le site de {nom_entreprise} : quelques points relevés',
                'body' => <<<MD
                Bonjour {prenom_dirigeant|Madame, Monsieur},

                Je m’appelle {expediteur}, je crée et modernise des sites internet pour les {secteur|entreprises} de Bourgogne.

                En visitant le site de {nom_entreprise}, j’ai relevé quelques points qui peuvent gêner vos visiteurs :

                {problemes_detectes}

                Rien d’alarmant, mais aujourd’hui la majorité des visites se font sur téléphone, et Google met en avant les sites rapides et sécurisés.

                Je peux vous proposer une remise à neuf de votre site, en gardant ce qui fonctionne déjà. Le devis est gratuit et sans engagement : une simple réponse à cet email suffit.

                {$signature}
                MD,
            ],
        ];

        // Relances (J+4 et J+12) pour chaque angle : courtes, puis un angle différent.
        foreach ([[TemplateAngle::NoWebsite, 'sans site'], [TemplateAngle::OutdatedWebsite, 'site obsolète']] as [$angle, $label]) {
            $templates[] = [
                'name' => "J+4 — Relance ({$label})",
                'angle' => $angle,
                'subject' => 'Re : {nom_entreprise} et votre présence en ligne',
                'body' => <<<MD
                Bonjour {prenom_dirigeant|Madame, Monsieur},

                Je me permets de revenir vers vous au sujet de mon précédent message concernant {nom_entreprise}, au cas où il serait passé inaperçu.

                Un site clair et adapté au mobile reste l’un des moyens les plus simples d’être trouvé par de nouveaux clients à {ville} et aux alentours, sans dépendre uniquement du bouche-à-oreille.

                Si le sujet vous intéresse, même pour plus tard, une simple réponse suffit : je vous prépare un devis gratuit et sans engagement, adapté à votre activité.

                {$signature}
                MD,
            ];

            $templates[] = [
                'name' => "J+12 — Dernière relance ({$label})",
                'angle' => $angle,
                'subject' => 'Un dernier mot pour {nom_entreprise}',
                'body' => <<<'MD'
                Bonjour {prenom_dirigeant|Madame, Monsieur},

                C’est mon dernier message, je ne voudrais pas vous importuner.

                Je travaille surtout avec des {secteur|petites entreprises} : des sites vitrines simples, rapides, faciles à mettre à jour, livrés en quelques semaines et à un prix pensé pour les petites structures. Vous pouvez en voir quelques exemples ci-dessous.

                Si ce n’est pas le bon moment, aucun souci. Et si un jour le projet se présente, il vous suffira de répondre à cet email.

                Merci pour votre temps, et bonne continuation à {nom_entreprise}.

                {expediteur}
                MD,
            ];
        }

        return $templates;
    }
}
