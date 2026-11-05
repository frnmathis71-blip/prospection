<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Coupe-circuit (mission M11) : si, sur 7 jours, les rebonds dépassent 3 % ou les
 * désinscriptions 2 % des emails partis, toutes les campagnes en cours passent en pause et
 * l'utilisateur est prévenu. Plus rien ne part tant qu'il n'a pas réarmé le coupe-circuit.
 * Après réarmement, seuls les incidents postérieurs comptent.
 */
class CircuitBreaker
{
    public const SETTING_KEY = 'circuit_breaker';

    public const BOUNCE_THRESHOLD = 3.0;

    public const UNSUBSCRIBE_THRESHOLD = 2.0;

    public const WINDOW_DAYS = 7;

    public function __construct(private readonly ProspectingStats $stats) {}

    public function isTripped(): bool
    {
        return filled($this->state()['tripped_at'] ?? null);
    }

    /**
     * @return array{tripped_at?: ?string, reason?: ?string, reset_at?: ?string}
     */
    public function state(): array
    {
        return (array) Setting::get(self::SETTING_KEY, []);
    }

    /**
     * Début de la fenêtre observée : 7 jours glissants, ou le dernier réarmement s'il est plus récent.
     */
    public function windowStart(): CarbonImmutable
    {
        $start = CarbonImmutable::now()->subDays(self::WINDOW_DAYS);
        $reset = $this->state()['reset_at'] ?? null;

        return $reset && CarbonImmutable::parse($reset)->gt($start) ? CarbonImmutable::parse($reset) : $start;
    }

    /**
     * @return array{sent: int, bounced: int, unsubscribed: int, bounce_rate: ?float, unsubscribe_rate: ?float}
     */
    public function health(): array
    {
        return $this->stats->health($this->windowStart());
    }

    /**
     * Vérifie les seuils ; déclenche si besoin. Renvoie vrai si le coupe-circuit est (ou vient d'être) déclenché.
     */
    public function check(): bool
    {
        if ($this->isTripped()) {
            return true;
        }

        $health = $this->health();
        $reasons = [];

        if (($health['bounce_rate'] ?? 0) > self::BOUNCE_THRESHOLD) {
            $reasons[] = "rebonds à {$health['bounce_rate']} % ({$health['bounced']} sur {$health['sent']})";
        }

        if (($health['unsubscribe_rate'] ?? 0) > self::UNSUBSCRIBE_THRESHOLD) {
            $reasons[] = "désinscriptions à {$health['unsubscribe_rate']} % ({$health['unsubscribed']} sur {$health['sent']})";
        }

        if ($reasons === []) {
            return false;
        }

        $this->trip(ucfirst(implode(' et ', $reasons)).' sur '.self::WINDOW_DAYS.' jours.');

        return true;
    }

    public function trip(string $reason): void
    {
        $paused = Campaign::query()->where('status', CampaignStatus::Active)->get();
        $paused->each(fn (Campaign $campaign) => $campaign->update(['status' => CampaignStatus::Paused]));

        Setting::put(self::SETTING_KEY, ['tripped_at' => now()->toIso8601String(), 'reason' => $reason] + $this->state());

        foreach (User::all() as $user) {
            Notification::make()
                ->title('Coupe-circuit déclenché : envois en pause')
                ->body($reason.' '.$paused->count().' campagne(s) mise(s) en pause. Vérifiez la liste et la délivrabilité avant de réarmer.')
                ->danger()
                ->icon('heroicon-o-shield-exclamation')
                ->actions([Action::make('campaigns')->label('Voir les campagnes')->url(CampaignResource::getUrl('index'))])
                ->sendToDatabase($user);
        }
    }

    /**
     * Réarmement par l'utilisateur : les envois peuvent reprendre (campagnes à relancer une par une).
     */
    public function reset(): void
    {
        Setting::put(self::SETTING_KEY, ['tripped_at' => null, 'reason' => null, 'reset_at' => now()->toIso8601String()]);
    }
}
