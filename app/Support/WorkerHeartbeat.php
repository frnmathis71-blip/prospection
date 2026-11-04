<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Signal de vie du worker de file d'attente, affiché sur le tableau de bord.
 *
 * Le worker « bat » à chaque tour de boucle et au début de chaque job (voir AppServiceProvider),
 * et les jobs longs battent aussi en cours de route. Sans battement récent, le worker est
 * considéré comme arrêté, même si des tâches attendent pour une raison normale (débit limité).
 */
class WorkerHeartbeat
{
    public const CACHE_KEY = 'queue-worker-heartbeat';

    /** Au-delà, le worker est considéré comme arrêté. */
    public const ALIVE_FOR_SECONDS = 90;

    public static function beat(): void
    {
        Cache::put(self::CACHE_KEY, now()->getTimestamp(), now()->addHour());
    }

    public static function last(): ?Carbon
    {
        $timestamp = Cache::get(self::CACHE_KEY);

        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    public static function isAlive(): bool
    {
        $last = self::last();

        return $last !== null && $last->diffInSeconds(now(), absolute: true) <= self::ALIVE_FOR_SECONDS;
    }
}
