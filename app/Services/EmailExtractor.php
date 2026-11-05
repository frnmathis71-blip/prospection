<?php

namespace App\Services;

use App\Support\Domain;
use Illuminate\Support\Str;

/**
 * Extraction des adresses email publiées sur une page (mission M7) : liens mailto:, adresses
 * écrites en clair, formes masquées courantes (« contact [at] domaine.fr », entités HTML,
 * protection d'email Cloudflare). Aucune adresse n'est devinée ou fabriquée.
 */
class EmailExtractor
{
    private const EMAIL_PATTERN = '/(?<![a-z0-9._%+-])[a-z0-9][a-z0-9._%+-]{0,63}@(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}(?![a-z0-9-])/i';

    /** Extensions de fichiers prises à tort pour des domaines (« logo@2x.png »). */
    private const FILE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif', 'css', 'js', 'ico', 'bmp', 'tiff'];

    /** Domaines d'exemple ou techniques (outils de suivi d'erreurs, plateformes) : jamais un contact. */
    private const IGNORED_DOMAINS = [
        'example.com', 'example.fr', 'example.org', 'exemple.fr', 'exemple.com', 'domain.com', 'domaine.fr',
        'domaine.com', 'votre-domaine.fr', 'votredomaine.fr', 'monsite.fr', 'sentry.io', 'wixpress.com',
        'sentry.wixpress.com', 'sentry-next.wixpress.com', 'ingest.sentry.io',
    ];

    /** Parties locales de modèles de formulaire (« votre@email.fr »). */
    private const PLACEHOLDER_LOCALS = ['votre', 'votre.email', 'votreemail', 'votre-email', 'your', 'your.email', 'youremail', 'email', 'nom', 'prenom.nom', 'name', 'user', 'username'];

    /**
     * @return list<string> adresses uniques, en minuscules, dans l'ordre d'apparition
     */
    public function extract(string $html): array
    {
        $found = [];

        // 1. Liens mailto: (« mailto:Contact%40exemple.fr?subject=Devis »).
        foreach ($this->links($html) as [$href]) {
            if (preg_match('/^\s*mailto:([^?#]+)/i', $href, $match)) {
                foreach (explode(',', rawurldecode($match[1])) as $address) {
                    $found[] = $address;
                }
            }
        }

        // 2. Protection d'email Cloudflare (data-cfemail / #email-protection-…).
        preg_match_all('/(?:data-cfemail\s*=\s*["\']|email-protection#)([0-9a-f]{4,})/i', $html, $protected);

        foreach ($protected[1] as $encoded) {
            $found[] = self::decodeCloudflare($encoded);
        }

        // 3. Adresses en clair ou masquées dans le texte visible (et les attributs value/content).
        $text = self::visibleText($html);
        preg_match_all(self::EMAIL_PATTERN, self::unmask($text), $plain);
        array_push($found, ...$plain[0]);

        return collect($found)
            ->map(fn (string $email) => mb_strtolower(trim($email, " \t\n\r\0\x0B.;,:<>()[]\"'")))
            ->filter(fn (string $email) => $this->isPlausible($email))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array{0: string, 1: string}> [href, texte] de chaque lien de la page
     */
    public function links(string $html): array
    {
        preg_match_all('/<a\b[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => [
            html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            trim(html_entity_decode(strip_tags($match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ], $matches);
    }

    /**
     * « contact [at] domaine [dot] fr », « contact(arobase)domaine.fr », « contact @ domaine.fr »
     * → « contact@domaine.fr ». Seules les formes entre crochets ou parenthèses sont reconnues,
     * pour ne pas fabriquer d'adresse à partir d'une phrase (« rendez-vous at home »).
     */
    public static function unmask(string $text): string
    {
        $text = preg_replace('/\s*[\[\(\{<]\s*(?:at|arobase|arrobase|@)\s*[\]\)\}>]\s*/iu', '@', $text);
        $text = preg_replace('/\s*[\[\(\{<]\s*(?:dot|point|\.)\s*[\]\)\}>]\s*/iu', '.', $text);

        // « contact @ domaine.fr » (espaces autour de l'arobase).
        return preg_replace('/([a-z0-9._%+-])\s+@\s+([a-z0-9-]+\.)/i', '$1@$2', $text);
    }

    /**
     * Protection Cloudflare : le premier octet est la clé, les suivants l'adresse chiffrée (XOR).
     */
    public static function decodeCloudflare(string $encoded): string
    {
        $key = hexdec(substr($encoded, 0, 2));
        $email = '';

        for ($i = 2; $i < strlen($encoded) - 1; $i += 2) {
            $email .= chr(hexdec(substr($encoded, $i, 2)) ^ $key);
        }

        return $email;
    }

    private function isPlausible(string $email): bool
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! preg_match(self::EMAIL_PATTERN, $email)) {
            return false;
        }

        $local = Str::before($email, '@');
        $domain = Str::afterLast($email, '@');

        return ! in_array(Str::afterLast($domain, '.'), self::FILE_EXTENSIONS, true)
            && ! collect(self::IGNORED_DOMAINS)->contains(fn (string $ignored) => $domain === $ignored || str_ends_with($domain, '.'.$ignored))
            && ! in_array($local, self::PLACEHOLDER_LOCALS, true)
            && ! preg_match('/^[0-9a-f]{24,}$/', $local) // identifiants techniques (Sentry…)
            && ! Domain::isDisposable($domain);
    }

    private static function visibleText(string $html): string
    {
        // Les attributs value/content/title peuvent porter une adresse affichée (champ, meta).
        preg_match_all('/\b(?:value|content|title)\s*=\s*(["\'])(.*?)\1/is', $html, $attributes);

        $text = preg_replace(['#<(script|style|noscript)\b[^>]*>.*?</\1>#is', '#<[^>]+>#'], ' ', $html);

        return html_entity_decode((string) $text.' '.implode(' ', $attributes[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
