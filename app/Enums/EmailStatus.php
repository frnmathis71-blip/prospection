<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie d'un email de prospection.
 */
enum EmailStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Replied = 'replied';
    case Bounced = 'bounced';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'En file',
            self::Sent => 'Envoyé',
            self::Replied => 'Répondu',
            self::Bounced => 'Rebond',
            self::Cancelled => 'Annulé',
            self::Failed => 'Échec d’envoi',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Sent => 'info',
            self::Replied => 'success',
            self::Bounced => 'danger',
            self::Cancelled => 'gray',
            self::Failed => 'danger',
        };
    }
}
