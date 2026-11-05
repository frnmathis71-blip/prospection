<?php

namespace App\Filament\Pages;

use App\Jobs\RecalculateScores;
use App\Models\Setting;
use App\Services\CompanyScorer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Réglages du score de priorité (mission M6) : un poids par critère, puis recalcul de
 * tous les scores en tâche de fond.
 *
 * @property-read Schema $form
 */
class ScoringSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Réglages du score';

    protected static ?string $title = 'Réglages du score';

    protected static ?string $slug = 'reglages-du-score';

    protected static ?int $navigationSort = 10;

    /** @var array<string, int>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(CompanyScorer::weights());
    }

    public function form(Schema $schema): Schema
    {
        $field = fn (string $key, string $label, ?string $help = null) => TextInput::make($key)
            ->label($label)
            ->helperText($help)
            ->numeric()
            ->integer()
            ->minValue(0)
            ->maxValue(100)
            ->required()
            ->suffix('pts');

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Site web')
                    ->description('Critère principal : l’entreprise a-t-elle besoin d’un site ?')
                    ->columns(3)
                    ->schema([
                        $field('website_none', 'Aucun site'),
                        $field('website_social_only', 'Réseau social seul'),
                        $field('website_directory', 'Annuaire ou mini-site gratuit'),
                        $field('website_offline', 'Site hors ligne'),
                        $field('website_parking', 'Page parking'),
                        $field('website_unknown', 'Site pas encore analysé'),
                        $field('website_outdated', 'Site obsolète (base)', 'Plus les points de chaque problème détecté.'),
                        $field('problem_high', 'Par problème fort', 'Pas de HTTPS, pas adapté au mobile.'),
                        $field('problem_medium', 'Par problème moyen', 'Copyright ancien, technologies datées, lenteur.'),
                        $field('problem_low', 'Par problème faible', 'Mentions légales, contact.'),
                        $field('website_max', 'Plafond de la partie « site »'),
                    ]),
                Section::make('Joignabilité')
                    ->columns(4)
                    ->schema([
                        $field('phone', 'Téléphone connu'),
                        $field('email_pro', 'Email professionnel'),
                        $field('email_personal', 'Email personnel seulement'),
                        $field('osm_active', 'Active sur OpenStreetMap'),
                    ]),
                Section::make('Profil de l’entreprise')
                    ->columns(3)
                    ->schema([
                        $field('new_company', 'Créée il y a moins de 6 mois'),
                        $field('established', 'Installée depuis 2 ans ou plus'),
                        $field('headcount_small', '1 à 9 salariés'),
                        $field('headcount_micro', 'Sans salarié'),
                        $field('headcount_medium', '10 à 49 salariés'),
                    ]),
                Section::make('Bonus par secteur')
                    ->description('Pour cibler en priorité les secteurs qui répondent le mieux.')
                    ->columns(3)
                    ->schema(collect(config('prospection.sectors'))
                        ->map(fn (array $sector, string $key) => $field("sector_{$key}", $sector['label']))
                        ->values()
                        ->all()),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Enregistrer et recalculer les scores')->submit('save'),
                        Action::make('reset')
                            ->label('Revenir aux poids par défaut')
                            ->color('gray')
                            ->requiresConfirmation()
                            ->action(fn () => $this->resetToDefaults()),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $weights = array_map('intval', $this->form->getState());

        Setting::put(CompanyScorer::SETTING_KEY, $weights);
        RecalculateScores::dispatch();

        Notification::make()
            ->title('Poids enregistrés')
            ->body('Les scores de toutes les entreprises sont recalculés en tâche de fond.')
            ->success()
            ->send();
    }

    public function resetToDefaults(): void
    {
        Setting::query()->where('key', CompanyScorer::SETTING_KEY)->delete();
        $this->form->fill(CompanyScorer::weights());
        RecalculateScores::dispatch();

        Notification::make()->title('Poids par défaut rétablis')->success()->send();
    }
}
