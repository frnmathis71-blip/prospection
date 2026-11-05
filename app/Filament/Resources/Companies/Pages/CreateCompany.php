<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Jobs\EnrichCompany;
use App\Services\CompanyScorer;
use Filament\Resources\Pages\CreateRecord;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    /** M6 : score calculé dès la création ; enrichissement et analyse du site ensuite. */
    protected function afterCreate(): void
    {
        app(CompanyScorer::class)->refresh($this->getRecord());
        EnrichCompany::dispatch($this->getRecord());
    }
}
