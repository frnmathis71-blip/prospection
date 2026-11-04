<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Pause globale d'OpenStreetMap (Overpass) quand le serveur public est saturé.
 *
 * Au lieu que chaque enrichissement réessaie de son côté (et martèle un serveur déjà
 * plein), une seule pause est partagée par tous les jobs : 1 min, puis 2, 4, 8… jusqu'à
 * 1 h. Le premier appel réussi remet la pause à zéro.
 */
class OverpassPause
{
    private const UNTIL_KEY = 'overpass-paused-until';

    private const BACKOFF_KEY = 'overpass-backoff-seconds';

    public const MIN_SECONDS = 60;

    public const MAX_SECONDS = 3600;

    public static function until(): ?Carbon
    {
        $timestamp = Cache::get(self::UNTIL_KEY);

        return is_numeric($timestamp) && $timestamp > now()->getTimestamp()
            ? Carbon::createFromTimestamp((int) $timestamp)
            : null;
    }

    public static function isActive(): bool
    {
        return self::until() !== null;
    }

    /**
     * Le serveur est saturé : pause, puis doublement de la prochaine.
     *
     * @return int durée de la pause, en secondes
     */
    public static function start(?int $retryAfter = null): int
    {
        $backoff = (int) Cache::get(self::BACKOFF_KEY, self::MIN_SECONDS);
        $seconds = max($backoff, $retryAfter ?? 0);

        Cache::put(self::UNTIL_KEY, now()->addSeconds($seconds)->getTimestamp(), now()->addDay());
        Cache::put(self::BACKOFF_KEY, min($backoff * 2, self::MAX_SECONDS), now()->addDay());

        return $seconds;
    }

    public static function reset(): void
    {
        Cache::forget(self::UNTIL_KEY);
        Cache::forget(self::BACKOFF_KEY);
    }

    /**
     * Délai avant de reprendre un job, étalé pour que les jobs ne repartent pas tous ensemble.
     */
    public static function retryDelay(): int
    {
        $remaining = (int) max(1, (self::until()?->getTimestamp() ?? now()->getTimestamp()) - now()->getTimestamp());

        return $remaining + random_int(0, 120);
    }
}
