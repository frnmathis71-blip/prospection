<?php

namespace App\Filament\Pages;

use App\Enums\ActivityType;
use App\Enums\ProspectStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Liste d'appels (mission M7) : prospects à traiter qui ont un téléphone mais aucun email
 * utilisable, du meilleur au moins bon score. Les appels sont passés à la main (jamais
 * d'appel automatisé) ; le résultat est noté dans l'historique.
 */
class CallList extends Page implements HasTable
{
    use InteractsWithTable;

    /** Résultats d'un appel et statut appliqué au prospect (null = inchangé, il reste dans la liste). */
    public const OUTCOMES = [
        'no_answer' => ['label' => 'Pas de réponse / messagerie', 'status' => null],
        'call_back' => ['label' => 'À rappeler', 'status' => null],
        'interested' => ['label' => 'Intéressé', 'status' => ProspectStatus::Replied],
        'email_given' => ['label' => 'M’a donné un email', 'status' => null],
        'not_interested' => ['label' => 'Pas intéressé', 'status' => ProspectStatus::Lost],
        'do_not_contact' => ['label' => 'Ne plus contacter', 'status' => ProspectStatus::DoNotContact],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?string $navigationLabel = 'Liste d’appels';

    protected static ?string $title = 'Liste d’appels';

    protected static ?string $slug = 'liste-appels';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $count = Company::query()->toCall()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Prospects sans email utilisable mais avec un téléphone, du meilleur au moins bon score. Un email trouvé (site, OpenStreetMap, saisie) les retire de la liste.'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Company::query()->toCall()->with('website'))
            ->defaultSort('score', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Entreprise')
                    ->wrap()
                    ->searchable()
                    ->description(fn (Company $record) => $record->naf_code)
                    ->url(fn (Company $record) => CompanyResource::getUrl('view', ['record' => $record])),
                TextColumn::make('phone')
                    ->label('Téléphone')
                    ->formatStateUsing(fn (?string $state) => CompanyResource::formatPhone($state))
                    ->url(fn (Company $record) => 'tel:'.$record->phone)
                    ->icon(Heroicon::OutlinedPhone)
                    ->color('primary')
                    ->copyable(),
                TextColumn::make('city')
                    ->label('Ville')
                    ->wrap()
                    ->formatStateUsing(fn (Company $record) => $record->department ? "{$record->city} ({$record->department})" : $record->city),
                TextColumn::make('score')
                    ->label('Score')
                    ->badge()
                    ->color(fn (?int $state) => CompanyResource::scoreColor($state))
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('website.state')->label('Site')->badge()->placeholder('Non analysé'),
                TextColumn::make('next_action_on')->label('Rappel')->date('d/m/Y')->sortable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('department')->label('Département')->options(CompanyResource::DEPARTMENTS)->multiple(),
            ])
            ->recordActions([
                self::logCallAction(),
            ]);
    }

    public static function logCallAction(): Action
    {
        return Action::make('logCall')
            ->label('Résultat de l’appel')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->modalHeading(fn (Company $record) => 'Appel : '.$record->name)
            ->schema([
                Select::make('outcome')
                    ->label('Résultat')
                    ->options(collect(self::OUTCOMES)->map(fn (array $outcome) => $outcome['label']))
                    ->required()
                    ->live(),
                DatePicker::make('call_back_on')
                    ->label('Rappeler le')
                    ->visible(fn (Get $get) => $get('outcome') === 'call_back'),
                Textarea::make('note')
                    ->label('Note')
                    ->helperText(fn (Get $get) => $get('outcome') === 'email_given'
                        ? 'Ajoutez l’adresse depuis le menu Contacts (source « Saisie manuelle »).'
                        : null),
            ])
            ->action(function (Company $record, array $data) {
                $outcome = self::OUTCOMES[$data['outcome']];

                $record->activities()->create([
                    'type' => ActivityType::Call,
                    'content' => trim($outcome['label'].'. '.($data['note'] ?? '')),
                    'occurred_at' => now(),
                ]);

                $record->update(array_filter([
                    'status' => $outcome['status'],
                    'next_action_on' => $data['call_back_on'] ?? null,
                ]));

                Notification::make()->title('Appel noté')->success()->send();
            });
    }
}
