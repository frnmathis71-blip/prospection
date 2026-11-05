<?php

namespace App\Filament\Resources\Sequences;

use App\Filament\Resources\Sequences\Pages\ManageSequences;
use App\Models\Sequence;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Séquences d'emails (J0 / J+4 / J+12) : un template par étape, 3 étapes au plus.
 */
class SequenceResource extends Resource
{
    protected static ?string $model = Sequence::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $modelLabel = 'séquence';

    protected static ?string $pluralModelLabel = 'séquences';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')->label('Nom')->required()->maxLength(255),
                Repeater::make('steps')
                    ->label('Étapes')
                    ->relationship()
                    ->orderColumn('position')
                    ->minItems(1)
                    ->maxItems(3)
                    ->columns(3)
                    ->schema([
                        TextInput::make('delay_days')
                            ->label('Jours ouvrés après J0')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(60)
                            ->default(0)
                            ->required(),
                        Select::make('template_id')
                            ->label('Template')
                            ->relationship('template', 'name')
                            ->required()
                            ->columnSpan(2),
                    ])
                    ->helperText('Étape 1 = premier email (J0). Jamais plus de 3 emails par prospect ; la séquence s’arrête sur réponse, désinscription ou rebond (M10).'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->wrap(),
                TextColumn::make('steps')
                    ->label('Étapes')
                    ->state(fn (Sequence $record) => $record->steps->map(fn ($step) => 'J+'.$step->delay_days.' : '.$step->template?->name)->all())
                    ->listWithLineBreaks(),
                TextColumn::make('campaigns_count')->label('Campagnes')->counts('campaigns')->alignCenter(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Modifier'),
                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->hidden(fn (Sequence $record) => $record->campaigns()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSequences::route('/'),
        ];
    }
}
