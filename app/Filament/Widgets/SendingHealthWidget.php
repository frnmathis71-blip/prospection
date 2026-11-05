<?php

namespace App\Filament\Widgets;

use App\Services\CircuitBreaker;
use App\Services\EmailSender;
use App\Support\BusinessCalendar;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Santé de l'envoi (M11) : volume du jour, rebonds et désinscriptions sur 7 jours, état du
 * coupe-circuit (pause automatique au-delà de 3 % de rebonds ou 2 % de désinscriptions).
 */
class SendingHealthWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'Santé de l’envoi';

    protected function getStats(): array
    {
        $breaker = app(CircuitBreaker::class);
        $health = $breaker->health();
        $today = EmailSender::sentCount(...BusinessCalendar::dayBounds());
        $cap = (int) config('prospection.sending.daily_cap');
        $percent = fn (?float $rate) => $rate === null ? '—' : str_replace('.', ',', (string) $rate).' %';
        $state = $breaker->state();

        return [
            Stat::make('Envoyés aujourd’hui', "{$today} / {$cap}")
                ->description(BusinessCalendar::isSendingTime() ? 'Créneau d’envoi ouvert' : 'Hors créneau d’envoi')
                ->icon(Heroicon::OutlinedPaperAirplane),
            Stat::make('Rebonds (7 jours)', $percent($health['bounce_rate']))
                ->description("{$health['bounced']} sur {$health['sent']} envoyés · seuil ".CircuitBreaker::BOUNCE_THRESHOLD.' %')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color(($health['bounce_rate'] ?? 0) > CircuitBreaker::BOUNCE_THRESHOLD ? 'danger' : 'success'),
            Stat::make('Désinscriptions (7 jours)', $percent($health['unsubscribe_rate']))
                ->description("{$health['unsubscribed']} sur {$health['sent']} envoyés · seuil ".CircuitBreaker::UNSUBSCRIBE_THRESHOLD.' %')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color(($health['unsubscribe_rate'] ?? 0) > CircuitBreaker::UNSUBSCRIBE_THRESHOLD ? 'danger' : 'success'),
            Stat::make('Coupe-circuit', $breaker->isTripped() ? 'Déclenché' : 'Armé')
                ->description($breaker->isTripped()
                    ? ($state['reason'] ?? '').' Réarmer depuis Campagnes.'
                    : 'Pause automatique au-delà des seuils')
                ->icon($breaker->isTripped() ? Heroicon::OutlinedShieldExclamation : Heroicon::OutlinedShieldCheck)
                ->color($breaker->isTripped() ? 'danger' : 'success'),
        ];
    }
}
