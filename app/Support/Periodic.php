<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Tâches planifiées « une fois par période » (hébergement mutualisé) : au lieu d'exiger une
 * minute précise (« 01:30 »), la tâche part au premier passage du planificateur après l'heure
 * prévue, une seule fois par jour / semaine / mois / heure. Une minute manquée (le planificateur
 * mutualisé s'arrête quelques minutes chaque heure) ne fait donc jamais sauter une tâche.
 *
 * À utiliser avec ->everyMinute()->when(Periodic::daily('cle', '01:30')).
 */
class Periodic
{
    public static function hourly(string $key): Closure
    {
        return self::due($key, fn (CarbonImmutable $now) => [true, $now->format('Y-m-d H')]);
    }

    public static function daily(string $key, string $time): Closure
    {
        return self::due($key, fn (CarbonImmutable $now) => [$now->format('H:i') >= $time, $now->toDateString()]);
    }

    /**
     * @param  int  $dayOfWeek  1 = lundi … 7 = dimanche
     */
    public static function weekly(string $key, int $dayOfWeek, string $time): Closure
    {
        return self::due($key, fn (CarbonImmutable $now) => [
            $now->format('N').$now->format('H:i') >= $dayOfWeek.$time,
            $now->format('o-W'),
        ]);
    }

    public static function monthly(string $key, int $day, string $time): Closure
    {
        return self::due($key, fn (CarbonImmutable $now) => [
            sprintf('%02d%s', $now->day, $now->format('H:i')) >= sprintf('%02d%s', $day, $time),
            $now->format('Y-m'),
        ]);
    }

    /**
     * @param  Closure(CarbonImmutable): array{0: bool, 1: string}  $rule  [heure atteinte ?, période en cours]
     */
    private static function due(string $key, Closure $rule): Closure
    {
        return function () use ($key, $rule) {
            [$reached, $period] = $rule(BusinessCalendar::local());

            if (! $reached || Cache::get("periodic:{$key}") === $period) {
                return false;
            }

            // Marquée avant l'exécution : jamais deux lancements pour la même période.
            Cache::forever("periodic:{$key}", $period);

            return true;
        };
    }
}
