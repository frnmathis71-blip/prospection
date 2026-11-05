<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Enums\CampaignStatus;
use App\Filament\Resources\Campaigns\CampaignResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    /** Une campagne naît toujours en brouillon : rien ne part avant « Lancer la campagne ». */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ['status' => CampaignStatus::Draft] + $data;
    }

    protected function getRedirectUrl(): string
    {
        return CampaignResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
