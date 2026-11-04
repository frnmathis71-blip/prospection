<?php

namespace App\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Actions\Imports\Models\Import;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Tableau de bord : suivi des imports CSV (en attente, en cours, terminés) et rapport d'erreurs.
 */
class ImportsWidget extends TableWidget
{
    protected static ?int $sort = 2;

    // Affiché dès l'ouverture du tableau de bord (pas de chargement différé).
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Imports CSV')
            ->description('Se met à jour toutes les 5 secondes.')
            ->query(fn (): Builder => Import::query()->withCount('failedRows'))
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Aucun import pour le moment')
            ->emptyStateDescription('Lancez un import depuis Entreprises → « Importer un CSV ».')
            ->emptyStateIcon(Heroicon::OutlinedArrowUpTray)
            ->columns([
                TextColumn::make('file_name')
                    ->label('Fichier')
                    ->wrap()
                    ->description(fn (Import $record) => 'Lancé le '.$record->created_at->format('d/m/Y à H:i')),
                TextColumn::make('status')
                    ->label('État')
                    ->badge()
                    ->state(fn (Import $record) => self::status($record))
                    ->color(fn (string $state) => match ($state) {
                        'Terminé' => 'success',
                        'Terminé avec erreurs' => 'warning',
                        'En cours' => 'info',
                        default => 'gray',
                    })
                    ->description(fn (Import $record) => self::completedAt($record)),
                TextColumn::make('progress')
                    ->label('Progression')
                    ->state(fn (Import $record) => self::progress($record))
                    ->description(fn (Import $record) => $record->processed_rows.' / '.$record->total_rows.' lignes traitées'),
                // Importées et en erreur dans une même colonne, pour tenir dans la largeur.
                TextColumn::make('successful_rows')
                    ->label('Résultat')
                    ->formatStateUsing(fn (int $state) => $state.' '.($state > 1 ? 'importées' : 'importée'))
                    ->description(fn (Import $record) => $record->failed_rows_count > 0
                        ? $record->failed_rows_count.' en erreur'
                        : 'aucune erreur'),
                TextColumn::make('failed_rows_count')
                    ->label('En erreur')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('failedRows')
                    ->label('Rapport')
                    ->iconButton()
                    ->tooltip('Télécharger le rapport des lignes en erreur')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('danger')
                    ->visible(fn (Import $record) => $record->failed_rows_count > 0)
                    ->url(fn (Import $record) => URL::signedRoute('filament.imports.failed-rows.download', [
                        'authGuard' => 'web',
                        'import' => $record,
                    ]), shouldOpenInNewTab: true),
            ]);
    }

    /**
     * « Le 04/11/2026 à 20:53 » (Filament stocke completed_at en timestamp Unix).
     */
    public static function completedAt(Import $import): ?string
    {
        $value = $import->completed_at;

        if ($value === null) {
            return null;
        }

        $date = is_numeric($value) ? Carbon::createFromTimestamp($value, config('app.timezone')) : Carbon::parse($value);

        return 'Le '.$date->format('d/m/Y à H:i');
    }

    public static function status(Import $import): string
    {
        return match (true) {
            $import->completed_at !== null && $import->failed_rows_count > 0 => 'Terminé avec erreurs',
            $import->completed_at !== null => 'Terminé',
            $import->processed_rows > 0 => 'En cours',
            default => 'En attente',
        };
    }

    public static function progress(Import $import): string
    {
        if ($import->total_rows === 0) {
            return '100 %';
        }

        return (int) floor(min($import->processed_rows, $import->total_rows) / $import->total_rows * 100).' %';
    }
}
