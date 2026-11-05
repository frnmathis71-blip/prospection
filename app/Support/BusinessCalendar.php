<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Jours ouvrés et créneaux d'envoi (mission M9) : du lundi au vendredi hors jours fériés
 * français, 8 h-11 h et 14 h-17 h (heure de Paris, cf. config('prospection.sending')).
 */
class BusinessCalendar
{
    public static function timezone(): string
    {
        return config('prospection.sending.timezone', 'Europe/Paris');
    }

    public static function local(?CarbonInterface $moment = null): CarbonImmutable
    {
        return CarbonImmutable::instance($moment ?? now())->setTimezone(self::timezone());
    }

    public static function isBusinessDay(CarbonInterface $moment): bool
    {
        $day = self::local($moment);

        return ! $day->isWeekend() && ! in_array($day->format('m-d'), self::holidays($day->year), true);
    }

    /**
     * Date (heure de Paris, début de journée) située $days jours ouvrés après celle de l'instant donné.
     * « J+4 » d'un email parti le lundi 9 novembre 2026 = lundi 16 (le 11 novembre est férié).
     */
    public static function addBusinessDays(CarbonInterface $moment, int $days): CarbonImmutable
    {
        $day = self::local($moment)->startOfDay();

        while ($days > 0) {
            $day = $day->addDay();

            if (self::isBusinessDay($day)) {
                $days--;
            }
        }

        return $day;
    }

    /**
     * Vrai si l'instant est un jour ouvré, dans un des créneaux d'envoi (début inclus, fin exclue).
     */
    public static function isSendingTime(?CarbonInterface $moment = null): bool
    {
        $local = self::local($moment);

        if (! self::isBusinessDay($local)) {
            return false;
        }

        $time = $local->format('H:i');

        foreach (config('prospection.sending.slots', []) as [$start, $end]) {
            if ($time >= $start && $time < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Début et fin de la journée (heure de Paris) contenant cet instant, en UTC pour les requêtes.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayBounds(?CarbonInterface $moment = null): array
    {
        $local = self::local($moment);

        return [$local->startOfDay()->utc(), $local->endOfDay()->utc()];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function hourBounds(?CarbonInterface $moment = null): array
    {
        $local = self::local($moment);

        return [$local->startOfHour()->utc(), $local->endOfHour()->utc()];
    }

    /**
     * Jours fériés français (métropole), au format « mm-jj ».
     *
     * @return list<string>
     */
    public static function holidays(int $year): array
    {
        $easter = self::easterSunday($year);

        return [
            '01-01', '05-01', '05-08', '07-14', '08-15', '11-01', '11-11', '12-25',
            $easter->addDay()->format('m-d'),   // lundi de Pâques
            $easter->addDays(39)->format('m-d'), // Ascension
            $easter->addDays(50)->format('m-d'), // lundi de Pentecôte
        ];
    }

    /**
     * Dimanche de Pâques (algorithme de Meeus/Jones/Butcher), sans l'extension calendar.
     */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, self::timezone());
    }
}
