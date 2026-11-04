<?php

namespace App\Filament\Resources\Searches;

use App\Enums\SearchStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Searches\Pages\CreateSearch;
use App\Filament\Resources\Searches\Pages\EditSearch;
use App\Filament\Resources\Searches\Pages\ListSearches;
use App\Filament\Resources\Searches\Pages\ViewSearch;
use App\Jobs\RunCompanySearch;
use App\Models\Search;
use App\Services\SearchCriteria;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SearchResource extends Resource
{
    protected static ?string $model = Search::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $modelLabel = 'recherche';

    protected static ?string $pluralModelLabel = 'recherches';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom de la recherche')
                            ->placeholder('Plombiers 71')
                            ->required()
                            ->maxLength(255),
                        Select::make('recurrence')
                            ->label('Relance automatique')
                            ->options(Search::RECURRENCES)
                            ->placeholder('Non, lancement manuel'),
                    ]),
                Section::make('Zone')
                    ->description('Au moins une zone ou un secteur est obligatoire.')
                    ->columns(3)
                    ->schema([
                        CheckboxList::make('criteria.departments')
                            ->label('Départements')
                            ->options(config('prospection.departments')),
                        TagsInput::make('criteria.postal_codes')
                            ->label('Codes postaux')
                            ->placeholder('71100')
                            ->nestedRecursiveRules(['regex:/^\d{5}$/']),
                        TagsInput::make('criteria.commune_codes')
                            ->label('Codes commune INSEE')
                            ->placeholder('71076')
                            ->helperText('Différent du code postal (ex. Chalon-sur-Saône : 71076).')
                            ->nestedRecursiveRules(['regex:/^(\d{5}|2[AB]\d{3})$/i']),
                    ]),
                Section::make('Secteur')
                    ->columns(2)
                    ->schema([
                        CheckboxList::make('criteria.sectors')
                            ->label('Presets de codes NAF')
                            ->options(collect(config('prospection.sectors'))->map(fn (array $sector) => $sector['label'])->all())
                            ->descriptions(collect(config('prospection.sectors'))->map(fn (array $sector) => implode(', ', $sector['naf']))->all()),
                        TagsInput::make('criteria.naf_codes')
                            ->label('Autres codes NAF')
                            ->placeholder('43.22A')
                            ->nestedRecursiveRules(['regex:/^\d{2}\.\d{2}[A-Za-z]$/']),
                    ]),
                Section::make('Taille et ancienneté')
                    ->columns(3)
                    ->schema([
                        CheckboxList::make('criteria.headcount_ranges')
                            ->label("Tranche d'effectif")
                            ->options(config('prospection.headcount_ranges'))
                            ->columns(2)
                            ->columnSpan(2),
                        TextInput::make('criteria.max_age_months')
                            ->label('Créées depuis moins de (mois)')
                            ->helperText('Ex. 6 : cible « création de site ».')
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('criteria.min_age_years')
                            ->label('Créées depuis plus de (ans)')
                            ->numeric()
                            ->minValue(1)
                            ->columnStart(3),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Critères')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('criteria')
                            ->label('Critères')
                            ->state(fn (Search $record) => $record->searchCriteria()->summary())
                            ->columnSpanFull(),
                        TextEntry::make('recurrence')
                            ->label('Relance automatique')
                            ->formatStateUsing(fn (?string $state) => Search::RECURRENCES[$state] ?? 'Manuelle')
                            ->placeholder('Manuelle'),
                        TextEntry::make('companies_count')
                            ->label('Entreprises ajoutées par cette recherche')
                            ->state(fn (Search $record) => $record->companies()->count())
                            ->url(fn (Search $record) => CompanyResource::getUrl('index', ['filters' => ['search_id' => ['value' => $record->id]]])),
                    ]),
                Section::make('Dernier lancement')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('status')->label('État')->badge()->placeholder('Jamais lancée'),
                        TextEntry::make('last_run_at')->label('Terminé le')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('last_run_stats.found')->label('Trouvées par l’API')->placeholder('—'),
                        TextEntry::make('last_run_stats.created')->label('Ajoutées')->placeholder('—'),
                        TextEntry::make('last_run_stats.already_known')->label('Déjà connues')->placeholder('—'),
                        TextEntry::make('last_run_stats.completed')->label('Complétées (import sans SIREN)')->placeholder('—'),
                        TextEntry::make('excluded')
                            ->label('Exclues')
                            ->state(fn (Search $record) => self::excludedSummary($record))
                            ->placeholder('—')
                            ->columnSpan(2),
                        TextEntry::make('last_error')->label('Dernier incident')->color('danger')->placeholder('Aucun')->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Recherche')
                    ->searchable()
                    ->wrap()
                    ->description(fn (Search $record) => $record->searchCriteria()->summary()),
                TextColumn::make('recurrence')
                    ->label('Relance')
                    ->formatStateUsing(fn (?string $state) => Search::RECURRENCES[$state] ?? 'Manuelle')
                    ->placeholder('Manuelle'),
                TextColumn::make('status')
                    ->label('État')
                    ->badge()
                    ->placeholder('Jamais lancée')
                    ->description(fn (Search $record) => $record->last_run_at ? 'Le '.$record->last_run_at->format('d/m/Y à H:i') : null),
                TextColumn::make('last_run_stats.created')
                    ->label('Ajoutées')
                    ->alignCenter()
                    ->placeholder('—')
                    ->description(fn (Search $record) => isset($record->last_run_stats['found']) ? 'sur '.$record->last_run_stats['found'].' trouvées' : null),
                TextColumn::make('companies_count')->label('Total')->counts('companies')->alignCenter(),
            ])
            ->recordActions([
                self::runAction()->iconButton()->tooltip('Lancer maintenant'),
                EditAction::make()->iconButton()->tooltip('Modifier'),
            ]);
    }

    /**
     * Met la recherche en file : elle tourne en tâche de fond (worker requis).
     */
    public static function runAction(): Action
    {
        return Action::make('run')
            ->label('Lancer la recherche')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Lancer la recherche ?')
            ->modalDescription('Les entreprises trouvées seront ajoutées en tâche de fond, puis complétées avec OpenStreetMap et la devinette de domaine. Seules les nouvelles entreprises sont ajoutées.')
            ->modalSubmitActionLabel('Lancer')
            ->disabled(fn (Search $record) => in_array($record->status, [SearchStatus::Queued, SearchStatus::Running], true))
            ->action(function (Search $record) {
                $record->update(['status' => SearchStatus::Queued, 'last_error' => null]);
                RunCompanySearch::dispatch($record);

                Notification::make()
                    ->title('Recherche mise en file')
                    ->body('Suivez son avancement ici ou sur le tableau de bord.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Refuse une recherche sans zone ni secteur (elle parcourrait toute la France).
     *
     * @param  array<string, mixed>  $data
     */
    public static function ensureHasCriteria(array $data): void
    {
        $criteria = SearchCriteria::fromArray($data['criteria'] ?? []);

        if (! $criteria->hasZone() && $criteria->allNafCodes() === []) {
            Notification::make()
                ->title('Choisissez au moins une zone ou un secteur')
                ->body('Sans critère, la recherche porterait sur toutes les entreprises de France.')
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    private static function excludedSummary(Search $record): ?string
    {
        $stats = $record->last_run_stats;

        if (! $stats) {
            return null;
        }

        return sprintf(
            '%d fermée(s), %d en procédure collective, %d hors ancienneté',
            $stats['excluded_closed'] ?? 0,
            $stats['excluded_procedure'] ?? 0,
            $stats['excluded_age'] ?? 0,
        ).(! empty($stats['truncated']) ? ' — résultats limités aux '.(config('prospection.recherche_entreprises.max_pages') * 25).' premiers' : '');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSearches::route('/'),
            'create' => CreateSearch::route('/create'),
            'view' => ViewSearch::route('/{record}'),
            'edit' => EditSearch::route('/{record}/edit'),
        ];
    }
}
