<?php

namespace App\Filament\Resources\Contacts;

use App\Enums\ContactType;
use App\Enums\ContactVerification;
use App\Enums\DataSource;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Models\Contact;
use App\Models\SuppressionEntry;
use BackedEnum;
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
                TextColumn::make('type')->label('Type')->badge(),
                TextColumn::make('verification')->label('Vérification')->badge(),
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
                EditAction::make()->iconButton()->tooltip('Modifier'),
                DeleteAction::make()->iconButton()->tooltip('Supprimer'),
            ]);
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
