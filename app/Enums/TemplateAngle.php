<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Angle commercial d'un modèle d'email.
 */
enum TemplateAngle: string implements HasColor, HasLabel
{
    case NoWebsite = 'no_website';
    case OutdatedWebsite = 'outdated_website';

    public function getLabel(): string
    {
        return match ($this) {
            self::NoWebsite => 'Sans site',
            self::OutdatedWebsite => 'Site obsolète',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NoWebsite => 'info',
            self::OutdatedWebsite => 'warning',
        };
    }
}
