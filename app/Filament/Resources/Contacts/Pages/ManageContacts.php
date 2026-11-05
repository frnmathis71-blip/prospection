<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Enums\ContactVerification;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ManageContacts extends ManageRecords
{
    protected static string $resource = ContactResource::class;

    /**
     * M7 : « À revoir » = adresses personnelles (gmail.com, orange.fr…) pas encore validées.
     */
    public function getTabs(): array
    {
        return [
            'toutes' => Tab::make('Toutes'),
            'a_revoir' => Tab::make('À revoir')
                ->icon(Heroicon::OutlinedEye)
                ->modifyQueryUsing(fn (Builder $query) => ContactResource::whereNeedsReview($query))
                ->badge(fn () => ContactResource::whereNeedsReview(Contact::query())->count() ?: null)
                ->badgeColor('warning'),
            'invalides' => Tab::make('Invalides')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('verification', ContactVerification::Invalid)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
