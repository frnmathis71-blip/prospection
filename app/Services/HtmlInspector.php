<?php

namespace App\Services;

use App\Enums\WebsiteProblem;
use Illuminate\Support\Str;

/**
 * Analyse du HTML d'une page d'accueil (mission M5) : affichage mobile, année du copyright,
 * technologies datées, mentions légales, moyen de contact.
 *
 * Seuls des constats vérifiables dans le code sont retenus (pas d'exagération des défauts).
 */
class HtmlInspector
{
    /** Versions en dessous desquelles un CMS est considéré comme ancien. */
    private const CMS_MINIMUM = [
        'WordPress' => '5.0',
        'Joomla' => '4.0',
        'Drupal' => '8.0',
        'PrestaShop' => '1.7',
    ];

    /** Un copyright vieux d'au moins N ans signale un site qui n'est plus entretenu. */
    public const COPYRIGHT_MAX_AGE = 3;

    /**
     * @return array{responsive: bool, copyright_year: ?int, technologies: array<string, ?string>, problems: list<WebsiteProblem>}
     */
    public function inspect(string $html, ?int $currentYear = null): array
    {
        $currentYear ??= (int) now()->format('Y');
        $html = Str::limit($html, 2_000_000, '');

        $responsive = $this->isResponsive($html);
        $copyrightYear = $this->copyrightYear($html, $currentYear);
        $technologies = $this->technologies($html);

        $problems = [];

        if (! $responsive) {
            $problems[] = WebsiteProblem::NotResponsive;
        }

        if ($copyrightYear !== null && $copyrightYear <= $currentYear - self::COPYRIGHT_MAX_AGE) {
            $problems[] = WebsiteProblem::OldCopyright;
        }

        foreach (self::CMS_MINIMUM as $cms => $minimum) {
            if (filled($technologies[$cms] ?? null) && version_compare($technologies[$cms], $minimum, '<')) {
                $problems[] = WebsiteProblem::OutdatedCms;
                break;
            }
        }

        if (filled($technologies['jQuery'] ?? null) && version_compare($technologies['jQuery'], '3.0', '<')) {
            $problems[] = WebsiteProblem::OldJquery;
        }

        if (array_key_exists('Flash', $technologies)) {
            $problems[] = WebsiteProblem::Flash;
        }

        if (array_key_exists('Frames', $technologies)) {
            $problems[] = WebsiteProblem::Frames;
        }

        if (! $responsive && $this->usesTableLayout($html)) {
            $problems[] = WebsiteProblem::TableLayout;
        }

        if (! $this->hasLegalNotice($html)) {
            $problems[] = WebsiteProblem::NoLegalNotice;
        }

        if (! $this->hasContact($html)) {
            $problems[] = WebsiteProblem::NoContact;
        }

        return [
            'responsive' => $responsive,
            'copyright_year' => $copyrightYear,
            'technologies' => $technologies,
            'problems' => $problems,
        ];
    }

    /**
     * Balise viewport adaptée aux téléphones (width=device-width).
     */
    public function isResponsive(string $html): bool
    {
        foreach ($this->metaTags($html) as $attributes) {
            if (mb_strtolower($attributes['name'] ?? '') === 'viewport'
                && str_contains(mb_strtolower(str_replace(' ', '', $attributes['content'] ?? '')), 'width=device-width')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Année la plus récente d'une mention de copyright (« © 2012 », « © 2015-2019 », « Copyright 2014 »).
     */
    public function copyrightYear(string $html, int $currentYear): ?int
    {
        $text = $this->visibleText($html);

        preg_match_all('/(?:©|\(c\)|copyright)[^0-9]{0,60}?((?:19|20)\d{2})(?:\s*(?:-|–|—|à|\/)\s*((?:19|20)\d{2}))?/iu', $text, $matches, PREG_SET_ORDER);

        $years = collect($matches)
            ->flatMap(fn (array $match) => array_filter([$match[1] ?? null, $match[2] ?? null]))
            ->map(fn ($year) => (int) $year)
            ->filter(fn (int $year) => $year >= 1995 && $year <= $currentYear);

        return $years->max();
    }

    /**
     * Technologies repérées : CMS (meta generator, chemins WordPress), jQuery, Flash, cadres.
     *
     * @return array<string, ?string>
     */
    public function technologies(string $html): array
    {
        $technologies = [];

        foreach ($this->metaTags($html) as $attributes) {
            if (mb_strtolower($attributes['name'] ?? '') !== 'generator' || blank($attributes['content'] ?? null)) {
                continue;
            }

            if (preg_match('/^(WordPress|Joomla!?|Drupal|PrestaShop|Wix\.com|Jimdo|Webflow|Squarespace|Shopify)\s*v?([\d.]+)?/i', trim($attributes['content']), $match)) {
                $name = match (mb_strtolower(rtrim($match[1], '!'))) {
                    'wordpress' => 'WordPress',
                    'joomla' => 'Joomla',
                    'drupal' => 'Drupal',
                    'prestashop' => 'PrestaShop',
                    'wix.com' => 'Wix',
                    default => ucfirst(mb_strtolower($match[1])),
                };
                $technologies[$name] = isset($match[2]) && $match[2] !== '' ? rtrim($match[2], '.') : null;
            }
        }

        // WordPress sans meta generator : les fichiers du cœur portent la version (?ver=4.9.8).
        if (! isset($technologies['WordPress']) && str_contains($html, '/wp-content/')) {
            $technologies['WordPress'] = preg_match('#/wp-includes/[^"\']+\?ver=(\d+\.\d+(?:\.\d+)?)#i', $html, $match) ? $match[1] : null;
        }

        if (preg_match('#jquery[.-](\d+\.\d+(?:\.\d+)?)(?:\.min|\.slim)*\.js#i', $html, $match)
            || preg_match('#/jquery/(\d+\.\d+(?:\.\d+)?)/jquery#i', $html, $match)
            || preg_match('#jquery(?:\.min)?\.js\?ver=(\d+\.\d+(?:\.\d+)?)#i', $html, $match)) {
            $technologies['jQuery'] = $match[1];
        }

        if (preg_match('/\.swf\b|application\/x-shockwave-flash/i', $html)) {
            $technologies['Flash'] = null;
        }

        if (preg_match('/<frameset\b|<frame\s/i', $html)) {
            $technologies['Frames'] = null;
        }

        return $technologies;
    }

    /**
     * Au moins deux tableaux avec des attributs de mise en page (largeur, couleur, espacement).
     */
    private function usesTableLayout(string $html): bool
    {
        return preg_match_all('/<table\b[^>]*\b(?:width|bgcolor|cellpadding|cellspacing|background)\s*=/i', $html) >= 2;
    }

    private function hasLegalNotice(string $html): bool
    {
        foreach ($this->links($html) as [$href, $text]) {
            $haystack = Str::lower(Str::ascii($href.' '.$text));

            if (preg_match('/mentions?[\s_-]*legales?|informations?[\s_-]*legales?|legal[\s_-]*notice|\blegal\b|impressum/', $haystack)) {
                return true;
            }
        }

        return false;
    }

    private function hasContact(string $html): bool
    {
        if (preg_match('/<form\b.*?(?:<textarea\b|type\s*=\s*["\']?email)/is', $html)) {
            return true;
        }

        foreach ($this->links($html) as [$href, $text]) {
            if (str_contains(Str::lower(Str::ascii($href.' '.$text)), 'contact')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, string>> attributs de chaque balise <meta>
     */
    private function metaTags(string $html): array
    {
        preg_match_all('/<meta\b([^>]*)>/i', $html, $tags);

        return array_map(function (string $attributes) {
            preg_match_all('/([a-z:-]+)\s*=\s*(["\'])(.*?)\2/is', $attributes, $pairs, PREG_SET_ORDER);

            return collect($pairs)->mapWithKeys(fn (array $pair) => [mb_strtolower($pair[1]) => html_entity_decode($pair[3])])->all();
        }, $tags[1]);
    }

    /**
     * @return list<array{0: string, 1: string}> [href, texte] de chaque lien
     */
    private function links(string $html): array
    {
        preg_match_all('/<a\b[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => [html_entity_decode($match[2]), html_entity_decode(strip_tags($match[3]))], $matches);
    }

    private function visibleText(string $html): string
    {
        $text = preg_replace(['#<(script|style|noscript)\b[^>]*>.*?</\1>#is', '#<[^>]+>#'], ' ', $html);

        return html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
