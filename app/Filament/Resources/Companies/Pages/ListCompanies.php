<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Imports\CompanyImporter;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Analyse (M5) de toutes les entreprises dont le site n'a pas encore été analysé.
            Action::make('analyzeMissing')
                ->label('Analyser les sites')
                ->icon(Heroicon::OutlinedMagnifyingGlassCircle)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Analyser les sites non analysés ?')
                ->modalDescription('Chaque site est visité une fois (robots.txt respecté), en tâche de fond et à débit limité. Les résultats apparaissent au fur et à mesure dans la colonne « Site ».')
                ->modalSubmitActionLabel('Lancer les analyses')
                ->action(function () {
                    Artisan::call('app:analyze-websites');

                    Notification::make()
                        ->title('Analyses mises en file')
                        ->body(trim(Artisan::output()))
                        ->success()
                        ->send();
                }),
            // Import CSV (M3) : choix des colonnes, traitement en tâche de fond,
            // notification de fin avec le rapport des lignes en erreur.
            ImportAction::make()
                ->label('Importer un CSV')
                ->modalHeading('Importer des entreprises depuis un fichier CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->importer(CompanyImporter::class)
                ->maxRows(10_000),
            CreateAction::make(),
        ];
    }
}
