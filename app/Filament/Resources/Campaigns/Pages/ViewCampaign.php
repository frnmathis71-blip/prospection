<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Enums\CampaignStatus;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CampaignResource::launchAction(),
            CampaignResource::pauseAction(),
            CampaignResource::resumeAction(),
            EditAction::make()->visible(fn (Campaign $record) => $record->status === CampaignStatus::Draft),
        ];
    }
}
