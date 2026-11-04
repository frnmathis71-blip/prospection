<?php

namespace App\Filament\Resources\Searches\Pages;

use App\Filament\Resources\Searches\SearchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSearch extends CreateRecord
{
    protected static string $resource = SearchResource::class;

    protected function beforeCreate(): void
    {
        SearchResource::ensureHasCriteria($this->data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
