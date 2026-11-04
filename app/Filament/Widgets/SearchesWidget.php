<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Searches\SearchResource;
use App\Models\Search;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tableau de bord : avancement des recherches d'entreprises (mission M4).
 */
class SearchesWidget extends TableWidget
{
    protected static ?int $sort = 3;

    // Affiché dès l'ouverture du tableau de bord (pas de chargement différé).
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recherches d’entreprises')
            ->description('Se met à jour toutes les 5 secondes.')
            ->query(fn (): Builder => Search::query()->whereNotNull('status'))
            ->defaultSort('updated_at', 'desc')
            ->poll('5s')
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Aucune recherche lancée')
            ->emptyStateDescription('Créez une recherche dans le menu « Recherches », puis lancez-la.')
            ->emptyStateIcon(Heroicon::OutlinedMagnifyingGlass)
            ->recordUrl(fn (Search $record) => SearchResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Recherche')
                    ->description(fn (Search $record) => $record->searchCriteria()->summary())
                    ->wrap(),
                TextColumn::make('status')
                    ->label('État')
                    ->badge()
                    ->description(fn (Search $record) => $record->last_error),
                TextColumn::make('progress')
                    ->label('Avancement')
                    ->state(fn (Search $record) => self::progress($record)),
                TextColumn::make('last_run_stats.created')
                    ->label('Ajoutées')
                    ->alignCenter()
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->label('Mise à jour')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i'),
            ]);
    }

    public static function progress(Search $search): string
    {
        $stats = $search->last_run_stats ?? [];

        if (! isset($stats['found'])) {
            return '—';
        }

        return ($stats['received'] ?? 0).' / '.min($stats['found'], config('prospection.recherche_entreprises.max_pages') * 25).' résultats parcourus';
    }
}
