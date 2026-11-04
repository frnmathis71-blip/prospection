<?php

namespace App\Filament\Resources\Searches\Pages;

use App\Filament\Resources\Searches\SearchResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSearch extends ViewRecord
{
    protected static string $resource = SearchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SearchResource::runAction(),
            EditAction::make()->color('gray'),
        ];
    }
}
