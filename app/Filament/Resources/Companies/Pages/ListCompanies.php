<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Imports\CompanyImporter;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
