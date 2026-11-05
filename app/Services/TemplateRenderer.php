<?php

namespace App\Services;

use App\Enums\DataSource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Template;
use App\Support\CompanyName;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Rendu d'un template pour un prospect (mission M8).
 *
 * Variables : {nom_entreprise}, {ville}, {secteur}, {prenom_dirigeant}, {problemes_detectes},
 * {annee_creation}, {expediteur}. Valeur de secours : {prenom_dirigeant|Madame, Monsieur}.
 * Une variable vide sans valeur de secours bloque l'envoi. Le corps est en Markdown ; le bouton
 * vers codenyr.fr (avec UTM) et le pied de page légal sont ajoutés automatiquement.
 * Aucune image ni pixel de suivi.
 */
class TemplateRenderer
{
    public const VARIABLES = [
        'nom_entreprise' => 'Nom de l’entreprise (« Plomberie Martin »)',
        'ville' => 'Ville',
        'secteur' => 'Secteur, au pluriel (« artisans du bâtiment »)',
        'prenom_dirigeant' => 'Prénom du dirigeant (rarement connu : prévoir une valeur de secours)',
        'problemes_detectes' => 'Problèmes détectés sur le site (M5), 3 au plus ; seule sur sa ligne = liste à puces',
        'annee_creation' => 'Année de création',
        'expediteur' => 'Ton prénom et ton nom',
    ];

    private const PLACEHOLDER = '/\{([a-z_]+)(?:\|([^{}\n]*))?\}/u';

    private const REQUIRED_SENDER_FIELDS = ['name' => 'nom (SENDER_NAME)', 'address' => 'adresse (SENDER_ADDRESS)', 'siret' => 'SIRET (SENDER_SIRET)'];

    public function render(Template $template, Company $company, ?Contact $contact = null, ?string $campaign = null, bool $preview = false): RenderedEmail
    {
        $company->loadMissing('website');
        $values = $this->values($company);
        $problems = self::problems($company);
        $blocking = [];

        $subject = Str::squish($this->substitute($template->subject, $values, $problems, $blocking, fn (string $value) => $value));

        // Pas d'image dans le corps (« ![texte](url) » devient un simple lien) : aucun pixel possible.
        $markdown = preg_replace('/!\[/', '[', str_replace("\r\n", "\n", $template->body));

        $markdownBody = $this->substitute($markdown, $values, $problems, $blocking, fn (string $value) => self::escapeMarkdown($value), list: true);
        $textBody = $this->substitute($markdown, $values, $problems, $ignored, fn (string $value) => $value, list: true);

        foreach (self::REQUIRED_SENDER_FIELDS as $field => $label) {
            if (blank(config("prospection.sender.{$field}"))) {
                $blocking[] = "Identité de l’expéditeur incomplète : {$label} à renseigner dans le .env.";
            }
        }

        $ctaUrl = $this->ctaUrl($template, $campaign);
        $ctaLabel = $template->cta_label ?: config('prospection.emails.cta_label');
        // Aperçu : lien sans signature (refusé s'il est cliqué), pour ne jamais désinscrire un prospect par erreur.
        $unsubscribeUrl = $contact ? self::unsubscribeUrl($contact->email, signed: ! $preview) : null;
        $footer = $this->footer($contact, $unsubscribeUrl);

        $bodyText = self::markdownToText($textBody);
        $wordCount = str_word_count(Str::ascii($bodyText));

        $html = view('emails.prospect', [
            'subject' => $subject,
            'bodyHtml' => (string) Str::markdown($markdownBody, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            'ctaUrl' => $ctaUrl,
            'ctaLabel' => $ctaLabel,
            'footer' => $footer,
        ])->render();

        $text = trim($bodyText)."\n\n{$ctaLabel} : {$ctaUrl}\n\n-- \n".implode("\n", array_column($footer, 'text'))."\n";

        return new RenderedEmail(
            subject: $subject,
            html: $html,
            text: $text,
            ctaUrl: $ctaUrl,
            unsubscribeUrl: $unsubscribeUrl,
            wordCount: $wordCount,
            blocking: array_values(array_unique($blocking)),
            warnings: $this->warnings($wordCount, $markdown, $contact),
        );
    }

    /**
     * Lien de désinscription signé (non devinable), valable sans connexion et sans limite de durée.
     */
    public static function unsubscribeUrl(string $email, bool $signed = true): string
    {
        $parameters = ['e' => rtrim(strtr(base64_encode(mb_strtolower($email)), '+/', '-_'), '=')];

        return $signed ? URL::signedRoute('unsubscribe', $parameters) : route('unsubscribe', $parameters);
    }

    /**
     * @return array<string, string|null>
     */
    public function values(Company $company): array
    {
        $sector = CompanyScorer::sectorOf($company->naf_code);

        return [
            'nom_entreprise' => filled($company->name) ? CompanyName::display($company->name) : null,
            'ville' => filled($company->city) ? CompanyName::titleIfUppercase($company->city) : null,
            'secteur' => $sector ? config("prospection.sectors.{$sector}.wording") : null,
            // Le nom du dirigeant n'est pas collecté (minimisation) : toujours la valeur de secours.
            'prenom_dirigeant' => null,
            'problemes_detectes' => self::sentence(self::problems($company)),
            'annee_creation' => $company->founded_on?->format('Y'),
            'expediteur' => config('prospection.sender.name'),
        ];
    }

    /**
     * @param  array<string, string|null>  $values
     * @param  list<string>  $problems
     * @param  list<string>|null  $blocking
     */
    private function substitute(string $text, array $values, array $problems, ?array &$blocking, callable $escape, bool $list = false): string
    {
        $blocking ??= [];

        // {problemes_detectes} seul sur sa ligne : liste à puces.
        if ($list && $problems !== []) {
            $text = preg_replace_callback(
                '/^[ \t]*\{problemes_detectes(?:\|[^{}\n]*)?\}[ \t]*$/mu',
                fn () => implode("\n", array_map(fn (string $label) => '- '.$escape(self::lowerFirst($label)), $problems)),
                $text,
            );
        }

        return preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($values, &$blocking, $escape) {
            [$placeholder, $name] = $match;
            $fallback = $match[2] ?? null;

            if (! array_key_exists($name, self::VARIABLES)) {
                $blocking[] = "Variable inconnue : {$placeholder}.";

                return $placeholder;
            }

            $value = $values[$name];

            if (blank($value)) {
                if ($fallback === null) {
                    $blocking[] = "La variable {{$name}} est vide pour ce prospect et n’a pas de valeur de secours.";

                    return '';
                }

                return $escape($fallback);
            }

            return $escape($value);
        }, $text);
    }

    /**
     * Problèmes réellement détectés sur le site (M5), les plus graves d'abord, 3 au plus.
     *
     * @return list<string>
     */
    public static function problems(Company $company): array
    {
        return collect($company->website?->problemLabels() ?? [])->take(3)->values()->all();
    }

    private function ctaUrl(Template $template, ?string $campaign): string
    {
        $base = config('prospection.emails.cta_url');
        $query = http_build_query([
            'utm_source' => config('prospection.emails.utm_source'),
            'utm_medium' => config('prospection.emails.utm_medium'),
            'utm_campaign' => Str::slug($campaign ?: $template->name),
            'utm_content' => Str::slug($template->angle->value.($template->variant ? '-'.$template->variant : '')),
        ]);

        return $base.(str_contains($base, '?') ? '&' : '?').$query;
    }

    /**
     * Pied de page légal : identité, mention d'information (source, finalité, durée),
     * confidentialité et désinscription.
     *
     * @return list<array{html: string, text: string}>
     */
    private function footer(?Contact $contact, ?string $unsubscribeUrl): array
    {
        $sender = config('prospection.sender');
        $identity = implode(' — ', array_filter([
            $sender['name'],
            trim($sender['company'].($sender['address'] ? ', '.$sender['address'] : '')),
            $sender['siret'] ? 'SIRET '.$sender['siret'] : null,
            $sender['phone'],
        ]));

        $information = 'Vous recevez cet email car les coordonnées de votre entreprise sont '.self::sourcePhrase($contact?->source)
            .'. Elles servent uniquement à vous présenter mes services et sont conservées '
            .config('prospection.emails.retention_years').' ans au plus sans réponse de votre part.';

        $privacy = $sender['privacy_url'];
        $unsubscribe = $unsubscribeUrl ?? '#';

        return [
            ['html' => e($identity), 'text' => $identity],
            ['html' => e($information).' <a href="'.e($privacy).'">Politique de confidentialité</a>.', 'text' => "{$information} Politique de confidentialité : {$privacy}"],
            [
                'html' => 'Vous ne souhaitez plus recevoir mes emails ? <a href="'.e($unsubscribe).'">Se désinscrire en un clic</a>.',
                'text' => "Vous ne souhaitez plus recevoir mes emails ? Se désinscrire en un clic : {$unsubscribe}",
            ],
        ];
    }

    public static function sourcePhrase(?DataSource $source): string
    {
        return match ($source) {
            DataSource::Website => 'publiées sur votre site internet',
            DataSource::OpenStreetMap => 'publiées sur OpenStreetMap',
            DataSource::RechercheEntreprises, DataSource::Sirene => 'publiées dans l’annuaire des entreprises (data.gouv.fr)',
            DataSource::Manual => 'publiées sur une page publique de votre entreprise',
            default => 'issues d’une source publique',
        };
    }

    /**
     * @return list<string>
     */
    private function warnings(int $wordCount, string $markdown, ?Contact $contact): array
    {
        $warnings = [];
        $min = (int) config('prospection.emails.min_words');
        $max = (int) config('prospection.emails.max_words');

        if ($wordCount > $max) {
            $warnings[] = "Email long : {$wordCount} mots (conseillé : {$min} à {$max}).";
        } elseif ($wordCount < $min) {
            $warnings[] = "Email court : {$wordCount} mots (conseillé : {$min} à {$max}).";
        }

        if (preg_match('/\]\(|https?:\/\//', $markdown)) {
            $warnings[] = 'Le corps contient un lien : un seul appel à l’action est conseillé (le bouton, ajouté automatiquement).';
        }

        if ($contact === null) {
            $warnings[] = 'Aperçu sans adresse email : le lien de désinscription sera ajouté à l’envoi.';
        } elseif ($contact->needsReview()) {
            $warnings[] = 'Adresse personnelle pas encore validée (Contacts → « À revoir »).';
        }

        return $warnings;
    }

    /**
     * « a », « a et b », « a, b et c » (premières lettres en minuscule).
     *
     * @param  list<string>  $items
     */
    private static function sentence(array $items): ?string
    {
        $items = array_map(fn (string $item) => self::lowerFirst($item), $items);

        return match (count($items)) {
            0 => null,
            1 => $items[0],
            default => implode(', ', array_slice($items, 0, -1)).' et '.end($items),
        };
    }

    private static function lowerFirst(string $text): string
    {
        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    private static function escapeMarkdown(string $value): string
    {
        return preg_replace('/([\\\\`*_\[\]<>#|~])/u', '\\\\$1', $value);
    }

    /**
     * Version texte brut lisible du Markdown : liens « texte (url) », sans gras ni titres.
     */
    public static function markdownToText(string $markdown): string
    {
        $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/u', '$1 ($2)', $markdown);
        $text = preg_replace(['/(\*\*|__)(.+?)\1/u', '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '/^#{1,6}\s*/mu', '/^\s*[*+]\s+/mu'], ['$2', '$1', '', '- '], $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
