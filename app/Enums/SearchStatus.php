<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * État du dernier lancement d'une recherche d'entreprises.
 */
enum SearchStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Running = 'running';
    case Retrying = 'retrying';
    case Done = 'done';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'En attente',
            self::Running => 'En cours',
            self::Retrying => 'Nouvelle tentative prévue',
            self::Done => 'Terminée',
            self::Failed => 'Échec',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Running => 'info',
            self::Retrying => 'warning',
            self::Done => 'success',
            self::Failed => 'danger',
        };
    }
}
