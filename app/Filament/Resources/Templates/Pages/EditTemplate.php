<?php

namespace App\Filament\Resources\Templates\Pages;

use App\Filament\Resources\Templates\TemplateResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditTemplate extends EditRecord
{
    protected static string $resource = TemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Aperçu')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url(fn () => TemplateResource::getUrl('preview', ['record' => $this->getRecord()])),
        ];
    }
}
