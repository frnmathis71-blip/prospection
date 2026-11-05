<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Enums\CampaignStatus;
use App\Filament\Resources\Campaigns\CampaignResource;
use Filament\Resources\Pages\EditRecord;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    /** Seul un brouillon se modifie : la liste ciblée est figée au lancement. */
    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless($this->getRecord()->status === CampaignStatus::Draft, 403);
    }

    protected function getRedirectUrl(): string
    {
        return CampaignResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
