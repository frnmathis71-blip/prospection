<?php

namespace App\Filament\Resources\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\EmailStatus;
use App\Enums\WebsiteState;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Filament\Resources\Campaigns\RelationManagers\EmailsRelationManager;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Templates\TemplateResource;
use App\Models\Campaign;
use App\Services\CampaignAudience;
use App\Services\CircuitBreaker;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Campagnes (mission M9) : liste ciblée + séquence + quotas. Une campagne naît en brouillon ;
 * seul l'utilisateur la lance, après une confirmation explicite.
 */
class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $modelLabel = 'campagne';

    protected static ?string $pluralModelLabel = 'campagnes';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Campagne')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nom')->required()->maxLength(255)->helperText('Repris dans le paramètre UTM « utm_campaign ».'),
                        Select::make('sequence_id')->label('Séquence')->relationship('sequence', 'name')->required(),
                    ]),
                Section::make('Liste ciblée')
                    ->description('Prospects « À traiter » avec une adresse vérifiée, hors liste d’opposition (adresses personnelles : seulement si validées). Une seule adresse par entreprise.')
                    ->columns(3)
                    ->schema([
                        Select::make('search_id')->label('Recherche d’origine')->relationship('search', 'name')->placeholder('Toutes'),
                        Select::make('criteria.website_states')
                            ->label('État du site')
                            ->options(WebsiteState::class)
                            ->multiple()
                            ->helperText('À accorder avec l’angle des templates de la séquence.'),
                        TextInput::make('criteria.min_score')->label('Score minimum')->numeric()->integer()->minValue(0)->maxValue(100),
                        Select::make('criteria.departments')->label('Départements')->options(CompanyResource::DEPARTMENTS)->multiple(),
                        Select::make('criteria.sectors')->label('Secteurs')->options(TemplateResource::sectorOptions())->multiple(),
                        TextInput::make('criteria.limit')
                            ->label('Nombre maximum de prospects')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText('Campagne pilote : 10.'),
                    ]),
                Section::make('Rythme d’envoi')
                    ->columns(2)
                    ->schema([
                        TextInput::make('daily_quota')->label('Emails par jour (au plus)')->numeric()->integer()->minValue(1)->maxValue(200)->default(10)->required(),
                        TextInput::make('hourly_quota')->label('Emails par heure (au plus)')->numeric()->integer()->minValue(1)->maxValue(30)->default(3)->required(),
                        Text::make(fn () => 'Jours ouvrés uniquement (hors jours fériés), de 8 h à 11 h et de 14 h à 17 h, 2 à 6 minutes entre deux emails. '
                            .'Plafond global du domaine : '.config('prospection.sending.daily_cap').' emails par jour (SENDING_DAILY_CAP).')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('status')->label('Statut')->badge(),
                        TextEntry::make('sequence.name')->label('Séquence'),
                        TextEntry::make('quotas')->label('Rythme')->state(fn (Campaign $record) => "{$record->daily_quota} / jour, {$record->hourly_quota} / heure"),
                        TextEntry::make('started_at')->label('Lancée le')->dateTime('d/m/Y H:i', config('prospection.sending.timezone'))->placeholder('Pas encore lancée'),
                        TextEntry::make('counts')
                            ->label('Emails')
                            ->state(fn (Campaign $record) => self::countsSummary($record))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->wrap()->description(fn (Campaign $record) => $record->sequence?->name),
                TextColumn::make('status')->label('Statut')->badge(),
                TextColumn::make('counts')->label('Emails')->state(fn (Campaign $record) => self::countsSummary($record))->wrap(),
                TextColumn::make('started_at')->label('Lancée le')->date('d/m/Y')->placeholder('—'),
            ])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip('Voir'),
                EditAction::make()->iconButton()->tooltip('Modifier')->visible(fn (Campaign $record) => $record->status === CampaignStatus::Draft),
            ]);
    }

    public static function countsSummary(Campaign $campaign): string
    {
        $counts = $campaign->emails()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        if ($counts->isEmpty()) {
            return 'Aucun';
        }

        return collect(EmailStatus::cases())
            ->filter(fn (EmailStatus $status) => $counts->has($status->value))
            ->map(fn (EmailStatus $status) => $counts[$status->value].' '.mb_strtolower($status->getLabel()))
            ->implode(' · ');
    }

    /**
     * Ce qui empêche de lancer la campagne.
     *
     * @return list<string>
     */
    public static function launchProblems(Campaign $campaign): array
    {
        $problems = [];

        foreach (['name' => 'SENDER_NAME', 'address' => 'SENDER_ADDRESS', 'siret' => 'SENDER_SIRET'] as $field => $env) {
            if (blank(config("prospection.sender.{$field}"))) {
                $problems[] = "Identité de l’expéditeur incomplète : {$env} à renseigner dans le .env.";
            }
        }

        if (app(CircuitBreaker::class)->isTripped()) {
            $problems[] = 'Le coupe-circuit est déclenché : le réarmer d’abord (liste des campagnes).';
        }

        if (! $campaign->sequence?->steps()->exists()) {
            $problems[] = 'La séquence n’a aucune étape.';
        }

        return $problems;
    }

    /**
     * Lancement : action volontaire de l'utilisateur, avec le nombre exact de destinataires et
     * une case à cocher de confirmation.
     */
    public static function launchAction(): Action
    {
        return Action::make('launch')
            ->label('Lancer la campagne')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('danger')
            ->visible(fn (Campaign $record) => $record->status === CampaignStatus::Draft)
            ->modalHeading('Lancer un envoi réel ?')
            ->modalDescription(function (Campaign $record) {
                $count = app(CampaignAudience::class)->recipients($record)->count();
                $mailer = config('mail.default');
                $lines = ["<strong>{$count} prospect(s)</strong> recevront le premier email de la séquence, au rythme de {$record->daily_quota} par jour au plus, dans les créneaux d’envoi."];

                if (in_array($mailer, ['log', 'array'], true)) {
                    $lines[] = "Le mailer actuel est « {$mailer} » : aucun email ne partira réellement.";
                }

                foreach (self::launchProblems($record) as $problem) {
                    $lines[] = '⚠ '.e($problem);
                }

                return new HtmlString(implode('<br>', $lines));
            })
            ->schema([
                Checkbox::make('confirm')
                    ->label('Je confirme lancer un envoi réel à ces prospects.')
                    ->accepted()
                    ->required(),
            ])
            ->modalSubmitActionLabel('Lancer')
            ->action(function (Campaign $record) {
                if ($problems = self::launchProblems($record)) {
                    Notification::make()->title('Campagne non lancée')->body(implode(' ', $problems))->danger()->send();

                    return;
                }

                $count = app(CampaignAudience::class)->enqueue($record);

                if ($count === 0) {
                    Notification::make()->title('Aucun prospect ne correspond')->body('Élargissez la liste ciblée.')->warning()->send();

                    return;
                }

                $record->update(['status' => CampaignStatus::Active, 'started_at' => now()]);

                Notification::make()
                    ->title('Campagne lancée')
                    ->body("{$count} email(s) en file. Ils partent un par un dans les créneaux d’envoi (worker et planificateur requis).")
                    ->success()
                    ->send();
            });
    }

    public static function pauseAction(): Action
    {
        return Action::make('pause')
            ->label('Mettre en pause')
            ->icon(Heroicon::OutlinedPause)
            ->color('warning')
            ->visible(fn (Campaign $record) => $record->status === CampaignStatus::Active)
            ->action(fn (Campaign $record) => $record->update(['status' => CampaignStatus::Paused]));
    }

    public static function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Reprendre')
            ->icon(Heroicon::OutlinedPlay)
            ->requiresConfirmation()
            ->modalDescription('Les emails encore en file repartent dans les créneaux d’envoi.')
            ->visible(fn (Campaign $record) => $record->status === CampaignStatus::Paused)
            ->action(function (Campaign $record) {
                if (app(CircuitBreaker::class)->isTripped()) {
                    Notification::make()->title('Coupe-circuit déclenché')->body('Réarmez-le d’abord depuis la liste des campagnes.')->danger()->send();

                    return;
                }

                $record->update(['status' => CampaignStatus::Active]);
            });
    }

    /**
     * Réarmement du coupe-circuit (M11) : après avoir vérifié la liste et la délivrabilité.
     * Les campagnes restent en pause : à reprendre une par une.
     */
    public static function resetCircuitBreakerAction(): Action
    {
        return Action::make('resetCircuitBreaker')
            ->label('Réarmer le coupe-circuit')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('danger')
            ->visible(fn () => app(CircuitBreaker::class)->isTripped())
            ->requiresConfirmation()
            ->modalHeading('Réarmer le coupe-circuit ?')
            ->modalDescription(fn () => (app(CircuitBreaker::class)->state()['reason'] ?? '').' Avant de réarmer : vérifier la liste ciblée, les adresses en rebond et la réputation du domaine. Les campagnes restent en pause : reprenez-les une par une. Seuls les incidents postérieurs au réarmement seront comptés.')
            ->action(function () {
                app(CircuitBreaker::class)->reset();
                Notification::make()->title('Coupe-circuit réarmé')->success()->send();
            });
    }

    public static function getRelations(): array
    {
        return [
            EmailsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'view' => ViewCampaign::route('/{record}'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }
}
