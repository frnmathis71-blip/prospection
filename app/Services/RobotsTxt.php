<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Respect du robots.txt d'un site avant toute visite (interdit : contourner robots.txt).
 *
 * Règles prises en compte : groupes « User-agent » (le nôtre, sinon « * ») et leurs
 * lignes Allow / Disallow (préfixes ; la règle la plus longue l'emporte).
 */
class RobotsTxt
{
    /** @var array<string, string|null> contenu déjà lu, par origine */
    private array $cache = [];

    public function allows(string $url): bool
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = ($parts['path'] ?? '/') ?: '/';

        $robots = $this->cache[$origin] ??= $this->fetch($origin);

        return $robots === null || self::isAllowed($robots, $path, config('prospection.user_agent'));
    }

    public static function isAllowed(string $robots, string $path, string $userAgent): bool
    {
        $agentToken = mb_strtolower(Str::before($userAgent, '/'));
        $groups = [];
        $agents = [];
        $readingAgents = false;

        foreach (preg_split('/\R/', $robots) as $line) {
            $line = trim(Str::before($line, '#'));

            if (! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = mb_strtolower($field);

            if ($field === 'user-agent') {
                if (! $readingAgents) {
                    $agents = [];
                }

                $agents[] = mb_strtolower($value);
                $readingAgents = true;

                continue;
            }

            $readingAgents = false;

            if (in_array($field, ['allow', 'disallow'], true)) {
                foreach ($agents as $agent) {
                    $groups[$agent][] = [$field, $value];
                }
            }
        }

        $rules = $groups[$agentToken] ?? $groups['*'] ?? [];
        $best = null;

        foreach ($rules as [$type, $prefix]) {
            if ($prefix === '' || ! str_starts_with($path, $prefix)) {
                continue; // « Disallow: » vide = tout est permis
            }

            if ($best === null || strlen($prefix) > strlen($best[1]) || (strlen($prefix) === strlen($best[1]) && $type === 'allow')) {
                $best = [$type, $prefix];
            }
        }

        return $best === null || $best[0] === 'allow';
    }

    private function fetch(string $origin): ?string
    {
        try {
            $response = Http::withUserAgent(config('prospection.user_agent'))
                ->timeout((int) config('prospection.domain_guess.timeout', 8))
                ->get($origin.'/robots.txt');
        } catch (ConnectionException) {
            return null; // pas de robots.txt lisible : la visite d'une seule page reste permise
        }

        return $response->successful() ? $response->body() : null;
    }
}
