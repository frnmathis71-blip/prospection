<?php

namespace App\Filament\Widgets;

use App\Services\ProspectingStats;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Entonnoir de prospection (M11) : trouvés → avec email → contactés → réponses → devis → signés,
 * avec le taux de passage d'une étape à la suivante et le chiffre d'affaires signé.
 */
class FunnelWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Entonnoir de prospection';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $funnel = app(ProspectingStats::class)->funnel();
        $step = fn (int $value, int $previous, string $of) => ($rate = ProspectingStats::rate($value, $previous)) === null
            ? '—'
            : str_replace('.', ',', (string) $rate)." % des {$of}";

        return [
            Stat::make('Entreprises trouvées', number_format($funnel['found'], 0, ',', ' '))
                ->icon(Heroicon::OutlinedBuildingStorefront),
            Stat::make('Avec email', number_format($funnel['with_email'], 0, ',', ' '))
                ->description($step($funnel['with_email'], $funnel['found'], 'trouvées'))
                ->icon(Heroicon::OutlinedAtSymbol),
            Stat::make('Contactées', $funnel['contacted'])
                ->description($step($funnel['contacted'], $funnel['with_email'], 'entreprises avec email'))
                ->icon(Heroicon::OutlinedPaperAirplane),
            Stat::make('Réponses', $funnel['replied'])
                ->description($step($funnel['replied'], $funnel['contacted'], 'contactées'))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('info'),
            Stat::make('Devis', $funnel['quoted'])
                ->description($step($funnel['quoted'], $funnel['replied'], 'réponses'))
                ->icon(Heroicon::OutlinedDocumentText),
            Stat::make('Signés', $funnel['won'])
                ->description($step($funnel['won'], $funnel['contacted'], 'contactées'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success'),
            Stat::make('Chiffre d’affaires signé', number_format($funnel['revenue'], 0, ',', ' ').' €')
                ->description('Montant des devis des prospects gagnés')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success'),
        ];
    }
}
