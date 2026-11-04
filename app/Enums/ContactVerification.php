<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Résultat de la vérification d'une adresse (syntaxe + MX).
 */
enum ContactVerification: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'À vérifier',
            self::Valid => 'Valide',
            self::Invalid => 'Invalide',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Valid => 'success',
            self::Invalid => 'danger',
        };
    }
}
