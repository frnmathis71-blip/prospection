<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut d'un prospect (cycle commercial + états bloquants).
 */
enum ProspectStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Qualified = 'qualified';
    case Contacted = 'contacted';
    case FollowedUp = 'followed_up';
    case Replied = 'replied';
    case QuoteSent = 'quote_sent';
    case Won = 'won';
    case Lost = 'lost';
    case Unsubscribed = 'unsubscribed';
    case Bounced = 'bounced';
    case DoNotContact = 'do_not_contact';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Nouveau',
            self::Qualified => 'Qualifié',
            self::Contacted => 'Contacté',
            self::FollowedUp => 'Relancé',
            self::Replied => 'A répondu',
            self::QuoteSent => 'Devis envoyé',
            self::Won => 'Gagné',
            self::Lost => 'Perdu',
            self::Unsubscribed => 'Désinscrit',
            self::Bounced => 'Rebond',
            self::DoNotContact => 'Ne pas contacter',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::Qualified => 'info',
            self::Contacted => 'info',
            self::FollowedUp => 'info',
            self::Replied => 'warning',
            self::QuoteSent => 'warning',
            self::Won => 'success',
            self::Lost => 'gray',
            self::Unsubscribed => 'danger',
            self::Bounced => 'danger',
            self::DoNotContact => 'danger',
        };
    }
}
