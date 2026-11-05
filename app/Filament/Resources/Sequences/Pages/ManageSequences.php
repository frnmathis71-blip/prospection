<?php

namespace App\Filament\Resources\Sequences\Pages;

use App\Filament\Resources\Sequences\SequenceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSequences extends ManageRecords
{
    protected static string $resource = SequenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
