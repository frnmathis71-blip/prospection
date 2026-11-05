<?php

namespace App\Filament\Resources\Companies;

use App\Enums\DataSource;
use App\Enums\ProspectStatus;
use App\Enums\WebsiteState;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Jobs\AnalyzeWebsite;
use App\Jobs\FindContactEmails;
use App\Models\Company;
use App\Models\Contact;
use App\Services\EmailFinder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'entreprise';

    protected static ?string $pluralModelLabel = 'entreprises';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    /** Départements de la zone cible (Bourgogne). */
    public const DEPARTMENTS = [
        '21' => "21 — Côte-d'Or",
        '58' => '58 — Nièvre',
        '71' => '71 — Saône-et-Loire',
        '89' => '89 — Yonne',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Entreprise')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nom')->required()->maxLength(255),
                        TextInput::make('siren')
                            ->label('SIREN')
                            ->regex('/^\d{3}\s?\d{3}\s?\d{3}$/')
                            ->unique(ignoreRecord: true),
                        TextInput::make('naf_code')->label('Code NAF')->maxLength(6),
                        TextInput::make('headcount_range')->label("Tranche d'effectif (INSEE)")->maxLength(2),
                        DatePicker::make('founded_on')->label('Date de création'),
                        Toggle::make('is_active')->label('Entreprise active')->default(true),
                    ]),
                Section::make('Coordonnées')
                    ->columns(2)
                    ->schema([
                        TextInput::make('address')->label('Adresse'),
                        TextInput::make('postal_code')->label('Code postal')->maxLength(5),
                        TextInput::make('city')->label('Ville'),
                        Select::make('department')->label('Département')->options(self::DEPARTMENTS),
                        TextInput::make('phone')->label('Téléphone')->tel(),
                    ]),
                Section::make('Suivi commercial')
                    ->columns(2)
                    ->schema([
                        Select::make('status')->label('Statut')->options(ProspectStatus::class)->default(ProspectStatus::New)->required(),
                        DatePicker::make('next_action_on')->label('Prochaine action'),
                        TextInput::make('quote_amount')->label('Montant du devis')->numeric()->suffix('€'),
                        Select::make('source')->label('Origine de la donnée')->options(DataSource::class)->default(DataSource::Manual)->required(),
                        Select::make('search_id')->label('Recherche d’origine')->relationship('search', 'name'),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Entreprise')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')->label('Nom'),
                        TextEntry::make('siren')->label('SIREN')->placeholder('—'),
                        TextEntry::make('naf_code')->label('Code NAF')->placeholder('—'),
                        TextEntry::make('address')->label('Adresse')->placeholder('—'),
                        TextEntry::make('city')->label('Ville')->formatStateUsing(fn (Company $record) => trim("{$record->postal_code} {$record->city}"))->placeholder('—'),
                        TextEntry::make('phone')->label('Téléphone')->placeholder('—'),
                        TextEntry::make('headcount_range')->label("Tranche d'effectif")->placeholder('—'),
                        TextEntry::make('founded_on')->label('Date de création')->date('d/m/Y')->placeholder('—'),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                    ]),
                Section::make('Suivi commercial')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('status')->label('Statut')->badge(),
                        TextEntry::make('score')
                            ->label('Score de priorité')
                            ->formatStateUsing(fn (?int $state) => $state === null ? null : "{$state}/100")
                            ->badge()
                            ->color(fn (?int $state) => self::scoreColor($state))
                            ->placeholder('—'),
                        TextEntry::make('next_action_on')->label('Prochaine action')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('quote_amount')->label('Montant du devis')->money('EUR')->placeholder('—'),
                        TextEntry::make('source')->label('Origine de la donnée')->badge(),
                        TextEntry::make('search.name')->label('Recherche d’origine')->placeholder('—'),
                        TextEntry::make('score_details')
                            ->label('Pourquoi ce score ?')
                            ->state(fn (Company $record) => collect($record->score_details ?? [])
                                ->map(fn (array $line) => '+'.$line['points'].' pts : '.$line['label'])
                                ->all() ?: null)
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('Pas encore calculé')
                            ->helperText('Poids modifiables dans « Réglages du score ».')
                            ->columnSpanFull(),
                    ]),
                Section::make('Site web')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('website.state')
                            ->label('État')
                            ->badge()
                            ->placeholder('Non analysé')
                            ->helperText(fn (Company $record) => $record->website?->analyzed_at
                                ? 'Analysé le '.$record->website->analyzed_at->timezone(config('app.timezone'))->format('d/m/Y à H:i')
                                : null),
                        TextEntry::make('website.url')
                            ->label('Lien du site')
                            ->url(fn (Company $record) => $record->website?->url, shouldOpenInNewTab: true)
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                            ->iconPosition('after')
                            ->color('primary')
                            ->copyable()
                            ->placeholder('Aucun site'),
                        TextEntry::make('website.pagespeed')
                            ->label('PageSpeed mobile')
                            ->formatStateUsing(fn (?int $state) => $state === null ? null : "{$state}/100")
                            ->color(fn (?int $state) => match (true) {
                                $state === null => null,
                                $state < 50 => 'danger',
                                $state < 90 => 'warning',
                                default => 'success',
                            })
                            ->placeholder('—'),
                        TextEntry::make('website.final_url')
                            ->label('Adresse finale (après redirections)')
                            ->visible(fn (Company $record) => filled($record->website?->final_url) && $record->website->final_url !== $record->website->url)
                            ->columnSpan(2),
                        TextEntry::make('website.https')
                            ->label('HTTPS')
                            ->formatStateUsing(fn (?bool $state) => $state ? 'Oui' : 'Non')
                            ->color(fn (?bool $state) => $state ? 'success' : 'danger')
                            ->placeholder('—'),
                        TextEntry::make('website.responsive')
                            ->label('Adapté au mobile')
                            ->formatStateUsing(fn (?bool $state) => $state ? 'Oui' : 'Non')
                            ->color(fn (?bool $state) => $state ? 'success' : 'danger')
                            ->placeholder('—'),
                        TextEntry::make('website.technologies')
                            ->label('Technologies')
                            ->state(fn (Company $record) => collect($record->website?->technologies ?? [])
                                ->map(fn (?string $version, string $name) => trim("{$name} {$version}"))
                                ->implode(', ') ?: null)
                            ->placeholder('—'),
                        TextEntry::make('website.analysis_note')
                            ->label('Remarque de l’analyse')
                            ->visible(fn (Company $record) => filled($record->website?->analysis_note))
                            ->columnSpanFull(),
                        TextEntry::make('website.problems')
                            ->label('Problèmes détectés')
                            ->state(fn (Company $record) => $record->website?->problemLabels() ?: null)
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('Aucun')
                            ->columnSpanFull(),
                        TextEntry::make('osm_signals')
                            ->label('Signaux OpenStreetMap')
                            ->state(fn (Company $record) => self::osmSummary($record))
                            ->helperText('Données © contributeurs OpenStreetMap (licence ODbL).')
                            ->visible(fn (Company $record) => filled($record->osm_signals))
                            ->columnSpanFull(),
                    ]),
                Section::make('Contacts')
                    ->description(fn (Company $record) => $record->emails_searched_at
                        ? 'Site exploré le '.$record->emails_searched_at->timezone(config('app.timezone'))->format('d/m/Y à H:i')
                        : null)
                    ->schema([
                        RepeatableEntry::make('contacts')
                            ->hiddenLabel()
                            ->columns(5)
                            ->placeholder('Aucun email trouvé')
                            ->schema([
                                TextEntry::make('email')->label('Email')->copyable(),
                                TextEntry::make('type')
                                    ->label('Type')
                                    ->badge()
                                    ->helperText(fn (Contact $record) => $record->needsReview() ? 'Adresse personnelle : à revoir (menu Contacts)' : null),
                                TextEntry::make('verification')
                                    ->label('Vérification')
                                    ->badge()
                                    ->helperText(fn (Contact $record) => $record->verification_note),
                                TextEntry::make('source')
                                    ->label('Source')
                                    ->badge()
                                    ->url(fn (Contact $record) => $record->source_url, shouldOpenInNewTab: true)
                                    ->helperText(fn (Contact $record) => $record->source_url ? CompanyResource::shortUrl($record->source_url) : null),
                                TextEntry::make('collected_at')->label('Collecté le')->date('d/m/Y'),
                            ]),
                    ]),
                Section::make('Historique')
                    ->schema([
                        RepeatableEntry::make('activities')
                            ->hiddenLabel()
                            ->columns(3)
                            ->placeholder('Aucune note')
                            ->schema([
                                TextEntry::make('occurred_at')->label('Date')->dateTime('d/m/Y H:i'),
                                TextEntry::make('type')->label('Type')->badge(),
                                TextEntry::make('content')->label('Contenu'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('score', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable(['name', 'siren', 'phone'])
                    ->wrap()
                    ->sortable()
                    ->description(fn (Company $record) => $record->naf_code),
                // Ville (département) avec le téléphone en dessous, pour garder le tableau dans la largeur.
                TextColumn::make('city')
                    ->label('Ville / téléphone')
                    ->wrap()
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (Company $record) => $record->department ? "{$record->city} ({$record->department})" : $record->city)
                    ->description(fn (Company $record) => self::formatPhone($record->phone) ?? 'Pas de téléphone'),
                TextColumn::make('status')->label('Statut')->badge()->sortable(),
                TextColumn::make('score')
                    ->label('Score')
                    ->badge()
                    ->color(fn (?int $state) => self::scoreColor($state))
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn (Company $record) => collect($record->score_details ?? [])
                        ->map(fn (array $line) => '+'.$line['points'].' '.$line['label'])
                        ->implode(' · ') ?: null)
                    ->placeholder('—'),
                // État du site en badge, avec l'adresse en dessous : la cellule ouvre le site dans un nouvel onglet.
                TextColumn::make('website.state')
                    ->label('Site')
                    ->badge()
                    ->placeholder('Non analysé')
                    ->description(fn (Company $record) => filled($record->website?->url)
                        ? new HtmlString('<span style="color: var(--primary-500)">'.e(self::shortUrl($record->website->url)).' ↗</span>')
                        : null)
                    ->url(fn (Company $record) => $record->website?->url, shouldOpenInNewTab: true)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'website',
                        fn (Builder $query) => $query->where('url', 'like', "%{$search}%"),
                    )),
                TextColumn::make('contacts_count')->label('Emails')->counts('contacts')->sortable()->alignCenter(),
                TextColumn::make('siren')->label('SIREN')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')->label('Origine')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Ajoutée le')->date('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(ProspectStatus::class)->multiple(),
                SelectFilter::make('department')->label('Département')->options(self::DEPARTMENTS)->multiple(),
                SelectFilter::make('city')
                    ->label('Ville')
                    ->searchable()
                    ->options(fn () => Company::query()->whereNotNull('city')->distinct()->orderBy('city')->pluck('city', 'city')->all()),
                SelectFilter::make('website_state')
                    ->label('État du site')
                    ->options(WebsiteState::class)
                    ->multiple()
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['values'] ?? null,
                        fn (Builder $query, array $states) => $query->whereHas('website', fn (Builder $query) => $query->whereIn('state', $states)),
                    )),
                TernaryFilter::make('has_email')
                    ->label('Email trouvé')
                    ->queries(
                        true: fn (Builder $query) => $query->has('contacts'),
                        false: fn (Builder $query) => $query->doesntHave('contacts'),
                    ),
                TernaryFilter::make('has_phone')
                    ->label('Téléphone')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('phone'),
                        false: fn (Builder $query) => $query->whereNull('phone'),
                    ),
                Filter::make('score')
                    ->label('Score minimum')
                    ->schema([TextInput::make('min')->label('Score minimum')->numeric()->minValue(0)->maxValue(100)])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        filled($data['min'] ?? null),
                        fn (Builder $query) => $query->where('score', '>=', (int) $data['min']),
                    ))
                    ->indicateUsing(fn (array $data) => filled($data['min'] ?? null) ? "Score ≥ {$data['min']}" : null),
                TernaryFilter::make('is_active')->label('Entreprise active'),
                SelectFilter::make('search_id')->label('Recherche d’origine')->relationship('search', 'name'),
                SelectFilter::make('source')->label('Origine de la donnée')->options(DataSource::class)->multiple(),
            ])
            // Actions en icônes (avec infobulle) pour que le tableau tienne dans la largeur.
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip('Voir'),
                EditAction::make()->iconButton()->tooltip('Modifier'),
            ])
            ->toolbarActions([
                BulkAction::make('analyze')
                    ->label('Analyser les sites')
                    ->icon(Heroicon::OutlinedMagnifyingGlassCircle)
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $records->each(fn (Company $company) => AnalyzeWebsite::dispatch($company));

                        Notification::make()
                            ->title($records->count().' analyse(s) mise(s) en file')
                            ->body('Résultats dans la colonne « Site » au fur et à mesure (worker requis).')
                            ->success()
                            ->send();
                    }),
                BulkAction::make('findEmails')
                    ->label('Chercher les emails')
                    ->icon(Heroicon::OutlinedAtSymbol)
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $records->each(fn (Company $company) => FindContactEmails::dispatch($company));

                        Notification::make()
                            ->title($records->count().' recherche(s) d’emails mise(s) en file')
                            ->body('Seuls les sites en ligne sont explorés (worker requis).')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * Recherche des emails publiés sur le site, en tâche de fond (fiche entreprise, M7).
     */
    public static function findEmailsAction(): Action
    {
        return Action::make('findEmails')
            ->label('Chercher les emails')
            ->icon(Heroicon::OutlinedAtSymbol)
            ->color('gray')
            ->visible(fn (Company $record) => in_array($record->website?->state, EmailFinder::CRAWLABLE_STATES, true))
            ->action(function (Company $record) {
                FindContactEmails::dispatch($record);

                Notification::make()
                    ->title('Recherche d’emails mise en file')
                    ->body('Accueil, page contact et mentions légales. Rechargez la fiche dans quelques instants (worker requis).')
                    ->success()
                    ->send();
            });
    }

    /**
     * Analyse du site d'une entreprise, en tâche de fond (fiche entreprise).
     */
    public static function analyzeAction(): Action
    {
        return Action::make('analyzeWebsite')
            ->label('Analyser le site')
            ->icon(Heroicon::OutlinedMagnifyingGlassCircle)
            ->color('gray')
            ->action(function (Company $record) {
                AnalyzeWebsite::dispatch($record);

                Notification::make()
                    ->title('Analyse du site mise en file')
                    ->body('Rechargez la fiche dans quelques instants (worker requis).')
                    ->success()
                    ->send();
            });
    }

    /**
     * Adresse lisible : sans http(s)://, sans www. ni / final, tronquée si trop longue.
     */
    public static function shortUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $short = preg_replace('#^https?://(www\.)?#', '', rtrim($url, '/'));

        return mb_strlen($short) > 26 ? mb_substr($short, 0, 25).'…' : $short;
    }

    public static function scoreColor(?int $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= 70 => 'success',
            $score >= 40 => 'warning',
            default => 'gray',
        };
    }

    /**
     * « Fiche OSM « Plomberie Martin » : horaires, téléphone, réseaux sociaux renseignés ».
     */
    public static function osmSummary(Company $company): ?string
    {
        $signals = $company->osm_signals ?? [];

        if ($signals === []) {
            return null;
        }

        $present = array_keys(array_filter([
            'horaires' => $signals['opening_hours'] ?? false,
            'téléphone' => $signals['phone'] ?? false,
            'réseaux sociaux' => $signals['social'] ?? false,
        ]));

        return 'Lieu « '.($signals['osm_name'] ?? $company->name).' » : '
            .($present ? implode(', ', $present).' renseignés (entreprise active et joignable)' : 'aucune information de contact');
    }

    /**
     * Numéro lisible : « 0313725197 » → « 03 13 72 51 97 ».
     */
    public static function formatPhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) === 10 ? trim(chunk_split($digits, 2, ' ')) : $phone;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'view' => ViewCompany::route('/{record}'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
