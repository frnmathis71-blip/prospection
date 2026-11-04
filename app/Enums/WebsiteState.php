<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * État du site d'une entreprise (renseigné par l'analyse, mission M5).
 *
 * « Obsolète » = site en ligne mais daté (HTTPS absent, pas responsive, copyright ancien…) :
 * cible de l'angle « site obsolète ». « Actif » = site en ligne et récent.
 */
enum WebsiteState: string implements HasColor, HasLabel
{
    case Unknown = 'unknown';
    case None = 'none';
    case SocialOnly = 'social_only';
    case Directory = 'directory';
    case Offline = 'offline';
    case Parking = 'parking';
    case Outdated = 'outdated';
    case Active = 'active';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unknown => 'Non analysé',
            self::None => 'Aucun site',
            self::SocialOnly => 'Réseau social seul',
            self::Directory => "Mini-site d'annuaire",
            self::Offline => 'Hors ligne',
            self::Parking => 'Page parking',
            self::Outdated => 'Obsolète',
            self::Active => 'Actif et récent',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unknown => 'gray',
            self::None => 'danger',
            self::SocialOnly => 'warning',
            self::Directory => 'warning',
            self::Offline => 'danger',
            self::Parking => 'danger',
            self::Outdated => 'warning',
            self::Active => 'success',
        };
    }
}
