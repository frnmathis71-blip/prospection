<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Nature d'une adresse email (mission M7). Une adresse personnelle est à revoir à la main.
 */
enum ContactType: string implements HasColor, HasLabel
{
    case Generic = 'generic';
    case Nominative = 'nominative';
    case Personal = 'personal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Generic => 'Générique',
            self::Nominative => 'Nominative',
            self::Personal => 'Personnelle',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Generic => 'success',
            self::Nominative => 'info',
            self::Personal => 'warning',
        };
    }
}
