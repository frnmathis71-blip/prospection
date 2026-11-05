<?php

namespace App\Services;

use App\Enums\WebsiteState;
use App\Models\Website;
use App\Support\Domain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Crawl léger d'un site pour trouver ses emails publiés (mission M7) : page d'accueil, page
 * contact et mentions légales, trouvées par les liens de l'accueil. Chaque page passe par
 * robots.txt, avec un délai entre deux pages. Aucune adresse n'est devinée.
 */
class EmailFinder
{
    /** États où le site est en ligne et peut publier une adresse. */
    public const CRAWLABLE_STATES = [WebsiteState::Active, WebsiteState::Outdated];

    private const CONTACT_PATTERN = '/contact|nous[\s_-]*(?:joindre|ecrire|trouver)|coordonnees|ecrivez|devis/';

    private const LEGAL_PATTERN = '/mentions?[\s_-]*legales?|informations?[\s_-]*legales?|legal[\s_-]*notice|\blegal\b|impressum/';

    public function __construct(
        private readonly EmailExtractor $extractor,
        private readonly RobotsTxt $robots,
    ) {}

    /**
     * @return array{emails: list<array{email: string, page: string}>, visited: list<string>, note: ?string}
     */
    public function find(Website $website): array
    {
        $result = ['emails' => [], 'visited' => [], 'note' => null];
        $home = $website->final_url ?: $website->url;

        if (blank($home) || ! in_array($website->state, self::CRAWLABLE_STATES, true)) {
            return ['note' => 'Pas de site en ligne à explorer.'] + $result;
        }

        if (! preg_match('#^https?://#i', $home)) {
            $home = 'http://'.$home;
        }

        if (parse_url($home, PHP_URL_PATH) === null) {
            $home .= '/';
        }

        $siteDomain = Domain::host($home);
        $pages = ['home' => $home];
        $found = [];

        while ($pages !== []) {
            $kind = array_key_first($pages);
            $url = array_shift($pages);

            if (! $this->robots->allows($url)) {
                $result['note'] = trim(($result['note'] ?? '').' Page refusée par robots.txt : '.$url);

                continue;
            }

            if ($result['visited'] !== []) {
                Sleep::for((int) config('prospection.email_search.delay_seconds', 2))->seconds();
            }

            $html = $this->fetch($url);
            $result['visited'][] = $url;

            if ($html === null) {
                continue;
            }

            foreach ($this->extractor->extract($html) as $email) {
                if ($this->accepts($email, $kind, $siteDomain)) {
                    $found[$email] ??= ['email' => $email, 'page' => $url];
                }
            }

            if ($kind === 'home') {
                $pages += $this->subpages($html, $url, $siteDomain);
            }
        }

        $result['emails'] = array_slice(array_values($found), 0, (int) config('prospection.email_search.max_emails_per_site', 5));

        return $result;
    }

    /**
     * Liens de l'accueil vers la page contact et les mentions légales (même site uniquement).
     *
     * @return array<string, string>
     */
    private function subpages(string $html, string $baseUrl, ?string $siteDomain): array
    {
        $pages = [];

        foreach ($this->extractor->links($html) as [$href, $text]) {
            $url = self::absolute($href, $baseUrl);

            if ($url === null || Domain::host($url) !== $siteDomain || rtrim($url, '/') === rtrim($baseUrl, '/')) {
                continue;
            }

            $haystack = Str::lower(Str::ascii(rawurldecode((string) parse_url($url, PHP_URL_PATH)).' '.$text));

            if (! isset($pages['contact']) && preg_match(self::CONTACT_PATTERN, $haystack)) {
                $pages['contact'] = $url;
            } elseif (! isset($pages['legal']) && preg_match(self::LEGAL_PATTERN, $haystack)) {
                $pages['legal'] = $url;
            }
        }

        return $pages;
    }

    /**
     * Les mentions légales citent souvent l'hébergeur ou l'agence web : on n'y garde que les
     * adresses du domaine du site et les messageries personnelles (mises en revue manuelle).
     */
    private function accepts(string $email, string $kind, ?string $siteDomain): bool
    {
        if ($kind !== 'legal') {
            return true;
        }

        $domain = Str::afterLast($email, '@');

        return Domain::isPersonalMailbox($domain)
            || ($siteDomain !== null && ($domain === $siteDomain || str_ends_with($domain, '.'.$siteDomain) || str_ends_with($siteDomain, '.'.$domain)));
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::withUserAgent(config('prospection.user_agent'))
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'fr-FR,fr;q=0.9'])
                ->timeout((int) config('prospection.email_search.timeout', 15))
                ->connectTimeout(10)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful() || ! str_contains(mb_strtolower($response->header('Content-Type') ?: 'text/html'), 'html')) {
            return null;
        }

        return $response->body();
    }

    private static function absolute(string $href, string $baseUrl): ?string
    {
        $href = trim(Str::before($href, '#'));

        if ($href === '' || preg_match('/^(mailto|tel|javascript|data):/i', $href)) {
            return null;
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $origin = $scheme.'://'.parse_url($baseUrl, PHP_URL_HOST).(($port = parse_url($baseUrl, PHP_URL_PORT)) ? ':'.$port : '');

        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $path = (string) parse_url($baseUrl, PHP_URL_PATH);
        $directory = str_ends_with($path, '/') ? $path : Str::beforeLast($path, '/').'/';

        return $origin.$directory.preg_replace('#^\./#', '', $href);
    }
}
