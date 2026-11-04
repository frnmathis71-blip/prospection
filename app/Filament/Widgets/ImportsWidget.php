<?php

namespace App\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Actions\Imports\Models\Import;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;

/**
 * Tableau de bord : suivi des imports CSV (en attente, en cours, terminés) et rapport d'erreurs.
 */
class ImportsWidget extends TableWidget
{
    protected static ?int $sort = 2;

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
                    }),
                TextColumn::make('progress')
                    ->label('Progression')
                    ->state(fn (Import $record) => self::progress($record))
                    ->description(fn (Import $record) => $record->processed_rows.' / '.$record->total_rows.' lignes traitées'),
                TextColumn::make('successful_rows')
                    ->label('Importées')
                    ->alignCenter(),
                TextColumn::make('failed_rows_count')
                    ->label('En erreur')
                    ->alignCenter()
                    ->color(fn (int $state) => $state > 0 ? 'danger' : null),
                TextColumn::make('completed_at')
                    ->label('Terminé le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('failedRows')
                    ->label('Rapport')
                    ->tooltip('Télécharger les lignes en erreur')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('danger')
                    ->visible(fn (Import $record) => $record->failed_rows_count > 0)
                    ->url(fn (Import $record) => URL::signedRoute('filament.imports.failed-rows.download', [
                        'authGuard' => 'web',
                        'import' => $record,
                    ]), shouldOpenInNewTab: true),
            ]);
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
