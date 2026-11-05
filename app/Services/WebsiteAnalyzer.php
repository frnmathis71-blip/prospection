<?php

namespace App\Services;

use App\Enums\WebsiteProblem;
use App\Enums\WebsiteState;
use App\Models\Website;
use App\Support\Domain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Analyse d'un site (mission M5) : état (aucun, réseau social, annuaire, hors ligne, parking,
 * obsolète, actif) puis HTTPS, affichage mobile, copyright, technologies, mentions légales,
 * contact et score PageSpeed. Une seule visite de la page d'accueil, robots.txt respecté.
 *
 * Aucune erreur réseau ne remonte : un site injoignable est classé « hors ligne » avec la raison.
 */
class WebsiteAnalyzer
{
    /** Hébergeurs de pages parking ou de domaines à vendre. */
    private const PARKING_HOSTS = [
        'sedoparking.com', 'sedo.com', 'parkingcrew.net', 'bodis.com', 'dan.com', 'afternic.com', 'hugedomains.com',
        'above.com', 'parklogic.com', 'domainmarket.com', 'undeveloped.com', 'uniregistry.com', 'buydomains.com',
    ];

    /** Textes typiques d'une page parking, d'un domaine à vendre ou d'une page par défaut d'hébergeur. */
    private const PARKING_TEXTS = [
        'domain is for sale', 'domain may be for sale', 'buy this domain', 'this domain is parked', 'parked free',
        'domain parking', 'parked domain', 'ce nom de domaine est a vendre', 'ce domaine est a vendre', 'domaine a vendre',
        'nom de domaine disponible', 'ce domaine a ete reserve', 'domaine reserve', 'site en construction',
        'votre site est en cours de construction', 'bienvenue sur votre hebergement', 'felicitations ! votre hebergement',
        'apache2 debian default page', 'welcome to nginx', 'iis windows server', 'index of /',
    ];

    public function __construct(
        private readonly HtmlInspector $inspector,
        private readonly PageSpeedClient $pageSpeed,
        private readonly RobotsTxt $robots,
    ) {}

    public function analyze(Website $website): Website
    {
        $website->fill($this->results($website) + ['analyzed_at' => now()])->save();

        return $website;
    }

    /**
     * @return array<string, mixed>
     */
    private function results(Website $website): array
    {
        $empty = [
            'final_url' => null, 'http_status' => null, 'analysis_note' => null, 'https' => null,
            'responsive' => null, 'copyright_year' => null, 'technologies' => null, 'problems' => [], 'pagespeed' => null,
        ];

        if (blank($website->url)) {
            return ['state' => WebsiteState::None] + $empty;
        }

        $url = preg_match('#^https?://#i', trim($website->url)) ? trim($website->url) : 'http://'.trim($website->url);

        // « https://exemple.fr » → « https://exemple.fr/ » : la page d'accueil, toujours avec un chemin.
        if (parse_url($url, PHP_URL_PATH) === null) {
            $url .= '/';
        }

        $host = Domain::host($url);

        if ($host === null) {
            return ['state' => WebsiteState::None, 'analysis_note' => 'Adresse de site invalide.'] + $empty;
        }

        if ($kind = Domain::kind($host)) {
            return ['state' => $kind === 'social' ? WebsiteState::SocialOnly : WebsiteState::Directory] + $empty;
        }

        if (! $this->robots->allows($url)) {
            return ['state' => WebsiteState::Unknown, 'analysis_note' => 'Analyse refusée par le robots.txt du site.'] + $empty;
        }

        // HTTPS d'abord ; certificat invalide : relecture sans vérification pour analyser quand même.
        $httpsUrl = preg_replace('#^http://#i', 'https://', $url);
        $fetch = $this->fetch($httpsUrl);
        $invalidCertificate = false;

        if ($fetch['error'] === 'ssl') {
            $fetch = $this->fetch($httpsUrl, verify: false);
            $invalidCertificate = $fetch['response'] !== null;
        }

        if (! $this->isUsable($fetch['response'])) {
            $httpFetch = $this->fetch(preg_replace('#^https://#i', 'http://', $url));

            if ($this->isUsable($httpFetch['response']) || $fetch['response'] === null) {
                $fetch = $httpFetch;
                $invalidCertificate = false;
            }
        }

        /** @var Response|null $response */
        $response = $fetch['response'];

        if ($response === null) {
            return ['state' => WebsiteState::Offline, 'analysis_note' => self::errorNote($fetch['error'])] + $empty;
        }

        $status = $response->status();
        $finalUrl = $fetch['final_url'];
        $base = ['final_url' => $finalUrl, 'http_status' => $status] + $empty;

        if ($status >= 500 || in_array($status, [404, 410], true)) {
            return ['state' => WebsiteState::Offline, 'analysis_note' => "La page d’accueil renvoie une erreur (HTTP {$status})."] + $base;
        }

        if ($status >= 400) {
            return ['state' => WebsiteState::Unknown, 'analysis_note' => "Accès refusé par le site (HTTP {$status})."] + $base;
        }

        $finalHost = Domain::host($finalUrl) ?? $host;

        if ($kind = Domain::kind($finalHost)) {
            return ['state' => $kind === 'social' ? WebsiteState::SocialOnly : WebsiteState::Directory,
                'analysis_note' => "Le site redirige vers {$finalHost}."] + $base;
        }

        $html = $response->body();

        if ($note = $this->parkingNote($finalHost, $html)) {
            return ['state' => WebsiteState::Parking, 'analysis_note' => $note] + $base;
        }

        $https = str_starts_with(mb_strtolower($finalUrl), 'https://') && ! $invalidCertificate;
        $inspection = $this->inspector->inspect($html);
        $problems = $inspection['problems'];

        if ($invalidCertificate) {
            array_unshift($problems, WebsiteProblem::InvalidCertificate);
        } elseif (! $https) {
            array_unshift($problems, WebsiteProblem::NoHttps);
        }

        $pagespeed = $this->pageSpeed->mobileScore($finalUrl);

        if ($pagespeed !== null && $pagespeed < (int) config('prospection.pagespeed.slow_below', 50)) {
            $problems[] = WebsiteProblem::SlowMobile;
        }

        $serious = collect($problems)->contains(fn (WebsiteProblem $problem) => $problem->severity() !== 'low');

        return [
            'state' => $serious ? WebsiteState::Outdated : WebsiteState::Active,
            'https' => $https,
            'responsive' => $inspection['responsive'],
            'copyright_year' => $inspection['copyright_year'],
            'technologies' => $inspection['technologies'],
            'problems' => array_values(array_unique(array_map(fn (WebsiteProblem $problem) => $problem->value, $problems))),
            'pagespeed' => $pagespeed,
            'analysis_note' => $pagespeed === null && config('prospection.pagespeed.enabled') ? 'Score PageSpeed indisponible pour le moment.' : null,
        ] + $base;
    }

    /**
     * @return array{response: ?Response, final_url: string, error: ?string}
     */
    private function fetch(string $url, bool $verify = true): array
    {
        try {
            $response = Http::withUserAgent(config('prospection.user_agent'))
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'fr-FR,fr;q=0.9'])
                ->timeout((int) config('prospection.website_analysis.timeout', 15))
                ->connectTimeout(10)
                ->withOptions([
                    'allow_redirects' => ['max' => 5, 'track_redirects' => true],
                    'verify' => $verify,
                ])
                ->get($url);
        } catch (ConnectionException $exception) {
            return ['response' => null, 'final_url' => $url, 'error' => self::errorType($exception->getMessage())];
        }

        $history = $response->headers()['X-Guzzle-Redirect-History'] ?? [];

        return ['response' => $response, 'final_url' => (string) (end($history) ?: $url), 'error' => null];
    }

    private function isUsable(?Response $response): bool
    {
        return $response !== null && $response->status() < 400;
    }

    private static function errorType(string $message): string
    {
        $message = mb_strtolower($message);

        return match (true) {
            str_contains($message, 'ssl') || str_contains($message, 'certificate') || str_contains($message, 'curl error 60')
                || str_contains($message, 'curl error 35') => 'ssl',
            str_contains($message, 'timed out') || str_contains($message, 'curl error 28') => 'timeout',
            str_contains($message, 'resolve host') || str_contains($message, 'curl error 6:') => 'dns',
            default => 'connection',
        };
    }

    private static function errorNote(?string $error): string
    {
        return match ($error) {
            'timeout' => 'Le site ne répond pas (délai dépassé).',
            'dns' => 'Le nom de domaine ne pointe plus vers aucun serveur.',
            'ssl' => 'Connexion sécurisée impossible (certificat refusé).',
            default => 'Le serveur du site refuse la connexion.',
        };
    }

    private function parkingNote(string $host, string $html): ?string
    {
        foreach (self::PARKING_HOSTS as $parking) {
            if ($host === $parking || str_ends_with($host, '.'.$parking)) {
                return "Le domaine redirige vers une page parking ({$host}).";
            }
        }

        $text = Str::of(Str::ascii(strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html))))
            ->lower()->squish()->toString();

        // Une page parking est courte : sur une vraie page, « site en construction » peut être
        // le métier de l'entreprise (bâtiment), pas l'état du site.
        if (mb_strlen($text) > 3000) {
            return null;
        }

        foreach (self::PARKING_TEXTS as $needle) {
            if (str_contains($text, $needle)) {
                return "Page parking ou page par défaut de l’hébergeur (« {$needle} »).";
            }
        }

        return null;
    }
}
