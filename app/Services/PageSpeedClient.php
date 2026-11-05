<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Google PageSpeed Insights (gratuit) : score de performance mobile, 0-100.
 *
 * Facultatif : un échec ou un quota dépassé ne bloque jamais l'analyse du site
 * (le score reste vide). Une clé gratuite (PAGESPEED_API_KEY) augmente le quota.
 */
class PageSpeedClient
{
    private const PAUSE_KEY = 'pagespeed-paused-until';

    public function mobileScore(string $url): ?int
    {
        if (! config('prospection.pagespeed.enabled', true) || Cache::get(self::PAUSE_KEY, 0) > now()->getTimestamp()) {
            return null;
        }

        try {
            $response = Http::withUserAgent(config('prospection.user_agent'))
                ->acceptJson()
                ->timeout((int) config('prospection.pagespeed.timeout', 90))
                ->get(config('prospection.pagespeed.url'), array_filter([
                    'url' => $url,
                    'strategy' => 'mobile',
                    'category' => 'performance',
                    'key' => config('prospection.pagespeed.key'),
                ]));
        } catch (ConnectionException) {
            return null;
        }

        if ($response->status() === 429) {
            // Quota atteint : on cesse d'interroger Google pendant une heure.
            Cache::put(self::PAUSE_KEY, now()->addHour()->getTimestamp(), now()->addHours(2));

            return null;
        }

        $score = $response->successful() ? $response->json('lighthouseResult.categories.performance.score') : null;

        return is_numeric($score) ? (int) round($score * 100) : null;
    }
}
