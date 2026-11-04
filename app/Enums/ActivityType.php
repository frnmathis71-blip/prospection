<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Type d'entrée dans l'historique d'un prospect.
 */
enum ActivityType: string implements HasColor, HasLabel
{
    case Note = 'note';
    case StatusChange = 'status_change';
    case EmailSent = 'email_sent';
    case EmailReceived = 'email_received';
    case Call = 'call';

    public function getLabel(): string
    {
        return match ($this) {
            self::Note => 'Note',
            self::StatusChange => 'Changement de statut',
            self::EmailSent => 'Email envoyé',
            self::EmailReceived => 'Email reçu',
            self::Call => 'Appel',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Note => 'gray',
            self::StatusChange => 'info',
            self::EmailSent => 'info',
            self::EmailReceived => 'success',
            self::Call => 'warning',
        };
    }
}
