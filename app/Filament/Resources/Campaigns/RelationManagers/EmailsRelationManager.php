<?php

namespace App\Filament\Resources\Campaigns\RelationManagers;

use App\Enums\EmailStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Email;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Emails d'une campagne, en lecture seule : statut, date d'envoi et raison d'une annulation.
 */
class EmailsRelationManager extends RelationManager
{
    protected static string $relationship = 'emails';

    protected static ?string $title = 'Emails';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('company.name')
                    ->label('Entreprise')
                    ->wrap()
                    ->url(fn (Email $record) => $record->company_id ? CompanyResource::getUrl('view', ['record' => $record->company_id]) : null),
                TextColumn::make('contact.email')->label('Adresse'),
                TextColumn::make('sequenceStep.position')->label('Étape')->alignCenter(),
                TextColumn::make('status')->label('Statut')->badge()->description(fn (Email $record) => $record->note),
                TextColumn::make('sent_at')->label('Envoyé le')->dateTime('d/m/Y H:i', config('prospection.sending.timezone'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(EmailStatus::class),
            ]);
    }
}
