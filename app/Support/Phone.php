<?php

namespace App\Support;

/**
 * Normalisation des numéros français, pour stocker et comparer un format unique.
 */
class Phone
{
    /**
     * Choisit un seul numéro quand un champ en contient plusieurs (« 03 85 … / 06 82 … »).
     *
     * Le fixe est préféré au portable : un portable est souvent celui du dirigeant, et le
     * cahier des charges interdit de collecter plus que nécessaire. Sans fixe, le premier
     * numéro valide est gardé.
     */
    public static function pickBusiness(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $numbers = collect(preg_split('#\s*(?:/|;|\||,|\bou\b|\bet\b|\n)\s*#iu', $value))
            ->map(fn (string $part) => self::normalize($part))
            ->filter(fn (?string $number) => $number !== null && preg_match('/^(0\d{9}|\+\d{8,15})$/', $number));

        if ($numbers->isEmpty()) {
            return self::normalize($value); // laissé tel quel : la validation signalera la ligne
        }

        return $numbers->first(fn (string $number) => ! self::isMobile($number)) ?? $numbers->first();
    }

    public static function isMobile(string $number): bool
    {
        return (bool) preg_match('/^0[67]/', $number);
    }

    /**
     * « +33 3 80 12 34 56 », « 0033380123456 », « 03.80.12.34.56 » → « 0380123456 ».
     * Un numéro non français ou incomplet est renvoyé avec ses seuls chiffres (et le + initial).
     */
    public static function normalize(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $phone = trim($phone);
        $international = str_starts_with($phone, '+');
        $digits = preg_replace('/\D/', '', $phone);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0033')) {
            $digits = '0'.substr($digits, 4);
        } elseif ($international && str_starts_with($digits, '33')) {
            $digits = '0'.substr($digits, 2);
        } elseif ($international) {
            return '+'.$digits;
        }

        // « 0 3 80… » saisi avec un zéro en trop après l'indicatif : +33 (0)3 80…
        if (strlen($digits) === 11 && str_starts_with($digits, '00')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }
}
