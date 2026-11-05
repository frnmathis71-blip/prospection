<?php

namespace App\Filament\Resources\Contacts;

use App\Enums\ActivityType;
use App\Enums\ContactType;
use App\Enums\ContactVerification;
use App\Enums\DataSource;
use App\Filament\Pages\Registry;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Models\Contact;
use App\Models\SuppressionEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $modelLabel = 'contact';

    protected static ?string $pluralModelLabel = 'contacts';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_id')
                    ->label('Entreprise')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('email')
                    ->label('Adresse email')
                    ->email()
                    ->required(),
                Select::make('type')->label('Type')->options(ContactType::class)->default(ContactType::Generic)->required(),
                Select::make('source')->label('Source')->options(DataSource::class)->default(DataSource::Manual)->required(),
                DateTimePicker::make('collected_at')->label('Collecté le')->default(now())->required(),
                Select::make('verification')->label('Vérification')->options(ContactVerification::class)->default(ContactVerification::Pending)->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('collected_at', 'desc')
            ->columns([
                TextColumn::make('email')->label('Adresse')->searchable()->sortable()->copyable(),
                TextColumn::make('company.name')
                    ->wrap()
                    ->label('Entreprise')
                    ->searchable()
                    ->url(fn (Contact $record) => CompanyResource::getUrl('view', ['record' => $record->company_id])),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->description(fn (Contact $record) => $record->needsReview() ? 'À revoir' : null),
                TextColumn::make('verification')
                    ->label('Vérification')
                    ->badge()
                    ->tooltip(fn (Contact $record) => $record->verification_note),
                IconColumn::make('suppressed')
                    ->label('Opposition')
                    ->state(fn (Contact $record) => $record->isSuppressed())
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedNoSymbol)
                    ->trueColor('danger')
                    ->falseIcon(Heroicon::OutlinedCheck)
                    ->falseColor('success'),
                // Source et date de collecte ensemble (« d'où tenez-vous mon adresse ? »).
                TextColumn::make('source')
                    ->label('Source / collecté le')
                    ->badge()
                    ->description(fn (Contact $record) => $record->collected_at?->format('d/m/Y'))
                    ->url(fn (Contact $record) => $record->source_url, shouldOpenInNewTab: true)
                    ->tooltip(fn (Contact $record) => $record->source_url ? 'Trouvé sur '.$record->source_url : null)
                    ->sortable(['collected_at']),
            ])
            ->filters([
                SelectFilter::make('type')->label('Type')->options(ContactType::class)->multiple(),
                SelectFilter::make('source')->label('Source')->options(DataSource::class)->multiple(),
                SelectFilter::make('verification')->label('Vérification')->options(ContactVerification::class),
                TernaryFilter::make('suppressed')
                    ->label("Sur la liste d'opposition")
                    ->queries(
                        true: fn (Builder $query) => self::whereSuppressed($query),
                        false: fn (Builder $query) => $query->whereNot(fn (Builder $query) => self::whereSuppressed($query)),
                    ),
            ])
            ->recordActions([
                self::approveAction(),
                Action::make('exportData')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->tooltip('Exporter les données de cette adresse (RGPD)')
                    ->action(fn (Contact $record) => Registry::download($record->email)),
                EditAction::make()->iconButton()->tooltip('Modifier'),
                DeleteAction::make()->iconButton()->tooltip('Supprimer'),
            ]);
    }

    /**
     * M7 : validation à la main d'une adresse personnelle (gmail.com, orange.fr…), après avoir
     * vérifié qu'elle sert bien à l'activité professionnelle.
     */
    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Valider')
            ->tooltip('Adresse personnelle utilisée pour l’activité : autoriser la prospection')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('warning')
            ->iconButton()
            ->visible(fn (Contact $record) => $record->needsReview())
            ->requiresConfirmation()
            ->modalHeading('Valider cette adresse personnelle ?')
            ->modalDescription('À faire seulement si l’adresse sert clairement à l’activité de l’entreprise (affichée sur son site, sa vitrine ou ses documents).')
            ->action(function (Contact $record) {
                $record->update(['reviewed_at' => now()]);
                $record->company?->activities()->create([
                    'type' => ActivityType::Note,
                    'content' => "Adresse personnelle {$record->email} validée pour la prospection.",
                    'occurred_at' => now(),
                ]);
            });
    }

    /**
     * Adresses personnelles pas encore validées à la main.
     */
    public static function whereNeedsReview(Builder $query): Builder
    {
        return $query->where('type', ContactType::Personal)->whereNull('reviewed_at');
    }

    /**
     * Restreint aux contacts dont l'adresse ou le domaine est sur la liste d'opposition.
     */
    private static function whereSuppressed(Builder $query): Builder
    {
        $emails = SuppressionEntry::query()->whereNotNull('email')->pluck('email');
        $domains = SuppressionEntry::query()->whereNotNull('domain')->pluck('domain');

        return $query->where(function (Builder $query) use ($emails, $domains) {
            $query->whereIn('email', $emails->all());

            foreach ($domains as $domain) {
                $query->orWhere('email', 'like', '%@'.$domain);
            }
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageContacts::route('/'),
        ];
    }
}
