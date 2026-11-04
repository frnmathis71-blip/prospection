<?php

namespace App\Filament\Resources\SuppressionEntries;

use App\Enums\SuppressionReason;
use App\Filament\Resources\SuppressionEntries\Pages\ManageSuppressionEntries;
use App\Models\SuppressionEntry;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Liste d'opposition : consultation et ajout uniquement.
 * Aucune modification ni suppression depuis l'interface (elle ne doit jamais être purgée).
 */
class SuppressionEntryResource extends Resource
{
    protected static ?string $model = SuppressionEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $modelLabel = "entrée d'opposition";

    protected static ?string $pluralModelLabel = "liste d'opposition";

    protected static ?string $navigationLabel = "Liste d'opposition";

    protected static ?string $slug = 'liste-opposition';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')
                    ->label('Adresse email')
                    ->email()
                    ->requiredWithout('domain')
                    ->unique()
                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? mb_strtolower(trim($state)) : null),
                TextInput::make('domain')
                    ->label('Ou domaine entier')
                    ->placeholder('exemple.fr')
                    ->requiredWithout('email')
                    ->regex('/^@?[a-z0-9.-]+\.[a-z]{2,}$/i')
                    ->unique()
                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? mb_strtolower(ltrim(trim($state), '@')) : null),
                Select::make('reason')
                    ->label('Motif')
                    ->options(SuppressionReason::class)
                    ->default(SuppressionReason::Manual)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')->label('Adresse')->searchable()->placeholder('—')->copyable(),
                TextColumn::make('domain')->label('Domaine')->searchable()->placeholder('—'),
                TextColumn::make('reason')->label('Motif')->badge(),
                TextColumn::make('created_at')->label('Ajoutée le')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('reason')->label('Motif')->options(SuppressionReason::class)->multiple(),
                TernaryFilter::make('kind')
                    ->label('Type')
                    ->trueLabel('Domaines entiers')
                    ->falseLabel('Adresses')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('domain'),
                        false: fn (Builder $query) => $query->whereNotNull('email'),
                    ),
            ]);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSuppressionEntries::route('/'),
        ];
    }
}
