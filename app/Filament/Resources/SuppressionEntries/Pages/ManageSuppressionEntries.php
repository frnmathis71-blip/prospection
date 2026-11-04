<?php

namespace App\Filament\Resources\SuppressionEntries\Pages;

use App\Filament\Resources\SuppressionEntries\SuppressionEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSuppressionEntries extends ManageRecords
{
    protected static string $resource = SuppressionEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
