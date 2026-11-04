<?php

namespace App\Filament\Resources\Searches\Pages;

use App\Filament\Resources\Searches\SearchResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSearch extends EditRecord
{
    protected static string $resource = SearchResource::class;

    protected function beforeSave(): void
    {
        SearchResource::ensureHasCriteria($this->data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            // Supprimer une recherche garde les entreprises qu'elle a trouvées.
            DeleteAction::make()->modalDescription('Les entreprises déjà ajoutées par cette recherche sont conservées.'),
        ];
    }
}
