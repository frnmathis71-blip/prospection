<?php

namespace App\Filament\Resources\Templates\Pages;

use App\Filament\Resources\Templates\TemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTemplate extends CreateRecord
{
    protected static string $resource = TemplateResource::class;

    /** Après création : directement l'aperçu avec un vrai prospect. */
    protected function getRedirectUrl(): string
    {
        return TemplateResource::getUrl('preview', ['record' => $this->getRecord()]);
    }
}
