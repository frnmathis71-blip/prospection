<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Motif d'inscription sur la liste d'opposition.
 */
enum SuppressionReason: string implements HasColor, HasLabel
{
    case Unsubscribed = 'unsubscribed';
    case HardBounce = 'hard_bounce';
    case Complaint = 'complaint';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unsubscribed => 'Désinscription',
            self::HardBounce => 'Rebond dur',
            self::Complaint => 'Plainte',
            self::Manual => 'Ajout manuel',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unsubscribed => 'danger',
            self::HardBounce => 'danger',
            self::Complaint => 'danger',
            self::Manual => 'gray',
        };
    }
}
