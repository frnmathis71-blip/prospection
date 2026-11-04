<?php

namespace App\Services;

use App\Models\Company;
use App\Support\CompanyName;
use App\Support\Domain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Devinette de domaine : « Plomberie Martin » → plomberie-martin.fr, plomberiemartin.fr,
 * puis .com. Un domaine n'est retenu que s'il existe (DNS), que robots.txt autorise la
 * visite et que la page d'accueil cite l'entreprise (nom distinctif + ville ou code postal).
 */
class DomainGuesser
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly RobotsTxt $robots,
    ) {}

    /**
     * Domaines candidats, du plus probable au moins probable.
     *
     * @return list<string>
     */
    public static function candidates(string $name): array
    {
        $words = CompanyName::words($name);

        if ($words === [] || CompanyName::distinctiveWords($name) === []) {
            return []; // un nom uniquement générique donnerait des domaines au hasard
        }

        // Avec puis sans les articles : « Domaine des Grands Prés » → domainedesgrandspres, domaine-des-grands-pres,
        // domaine-grands-pres, domainegrandspres (les vignerons gardent souvent le « des » dans leur domaine).
        $full = CompanyName::wordsWithArticles($name);
        $labels = array_values(array_unique([
            implode('', $full),
            implode('-', $full),
            implode('-', $words),
            implode('', $words),
        ]));
        $domains = [];

        foreach (config('prospection.domain_guess.tlds', ['fr', 'com']) as $tld) {
            foreach ($labels as $label) {
                if (strlen($label) >= 3 && strlen($label) <= 63) {
                    $domains[] = "{$label}.{$tld}";
                }
            }
        }

        return $domains;
    }

    /**
     * Adresse du site trouvé, ou null.
     */
    public function guess(Company $company): ?string
    {
        foreach (self::candidates($company->name) as $domain) {
            if (! $this->dns->hasAddress($domain)) {
                continue;
            }

            if ($url = $this->verify($domain, $company)) {
                return $url;
            }
        }

        return null;
    }

    private function verify(string $domain, Company $company): ?string
    {
        foreach (["https://{$domain}/", "http://{$domain}/"] as $url) {
            if (! $this->robots->allows($url)) {
                return null; // visite refusée par le site : on ne contourne pas
            }

            try {
                $response = Http::withUserAgent(config('prospection.user_agent'))
                    ->timeout((int) config('prospection.domain_guess.timeout', 8))
                    ->withOptions(['allow_redirects' => ['max' => 3, 'track_redirects' => true]])
                    ->get($url);
            } catch (ConnectionException) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            // Une redirection vers un réseau social ou un autre domaine n'est pas « leur » site.
            $history = $response->headers()['X-Guzzle-Redirect-History'] ?? [];
            $finalUrl = (string) (end($history) ?: $url);

            if (Domain::fromUrl($finalUrl) === null) {
                return null;
            }

            $html = Str::limit($response->body(), 500_000, '');

            if ($this->mentionsCompany($html, $company)) {
                return $finalUrl;
            }

            return null; // domaine actif mais sans rapport avéré (homonyme, page parking…)
        }

        return null;
    }

    private function mentionsCompany(string $html, Company $company): bool
    {
        // Texte visible : sans scripts ni styles, balises remplacées par des espaces
        // (sinon « Martin</title><body>Votre » deviendrait « MartinVotre »).
        $text = preg_replace(['#<(script|style)\b[^>]*>.*?</\1>#is', '#<[^>]+>#'], ' ', $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (filled($company->siren) && str_contains(preg_replace('/\s+/', '', $text), $company->siren)) {
            return true;
        }

        $normalize = fn (string $value) => Str::of(Str::ascii($value))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        $placeMentioned = (filled($company->postal_code) && str_contains($text, $company->postal_code))
            || (filled($company->city) && str_contains(' '.$normalize($text).' ', ' '.$normalize($company->city).' '));

        return $placeMentioned && CompanyName::appearsIn($company->name, $text);
    }
}
