<?php

namespace App\Filament\Resources\Templates\Pages;

use App\Enums\TemplateAngle;
use App\Enums\WebsiteState;
use App\Filament\Resources\Templates\TemplateResource;
use App\Mail\ProspectMail;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SuppressionEntry;
use App\Models\Template;
use App\Services\RenderedEmail;
use App\Services\TemplateRenderer;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;

/**
 * Aperçu d'un template avec les données d'un vrai prospect (mission M8), et envoi d'un test
 * vers ses propres boîtes (Gmail, Outlook, mobile).
 *
 * @property-read Schema $form
 * @property Template $record
 */
class PreviewTemplate extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TemplateResource::class;

    protected static ?string $title = 'Aperçu';

    /** @var array{company_id: ?int, contact_id: ?int} */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $company = $this->defaultCompany();
        $this->form->fill([
            'company_id' => $company?->id,
            'contact_id' => $company?->contacts()->orderByRaw("type = 'generic' desc")->value('id'),
        ]);
    }

    public function getTitle(): string
    {
        return 'Aperçu : '.$this->record->name;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Select::make('company_id')
                    ->label('Prospect')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Company::query()
                        ->where('name', 'like', "%{$search}%")
                        ->orderByDesc('score')
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn (Company $company) => [$company->id => self::companyLabel($company)])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => ($company = Company::find($value)) ? self::companyLabel($company) : null)
                    ->live()
                    ->afterStateUpdated(fn (Set $set, $state) => $set('contact_id', Contact::query()
                        ->where('company_id', $state)
                        ->orderByRaw("type = 'generic' desc")
                        ->value('id'))),
                Select::make('contact_id')
                    ->label('Adresse')
                    ->placeholder('Aucune adresse (liste d’appels)')
                    ->options(fn () => Contact::query()
                        ->where('company_id', $this->data['company_id'] ?? null)
                        ->pluck('email', 'id')
                        ->all())
                    ->live(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')]),
            View::make('filament.templates.preview')->viewData(fn () => [
                'email' => $this->rendered(),
                'values' => ($company = $this->company()) ? app(TemplateRenderer::class)->values($company) : [],
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $recipients = config('prospection.emails.test_recipients');

        return [
            Action::make('sendTest')
                ->label('M’envoyer un test')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->disabled(fn () => $recipients === [] || $this->company() === null)
                ->tooltip($recipients === [] ? 'Renseigner EMAIL_TEST_RECIPIENTS (tes propres adresses) dans le .env' : null)
                ->schema([
                    Select::make('recipient')
                        ->label('Envoyer à')
                        ->options(array_combine($recipients, $recipients))
                        ->default($recipients[0] ?? null)
                        ->required(),
                ])
                ->modalDescription('Rendu avec ce prospect, envoyé uniquement à ta propre adresse (objet préfixé « [TEST] »). Le lien de désinscription du test concerne ton adresse, pas celle du prospect.')
                ->action(fn (array $data) => $this->sendTest($data['recipient'])),
            Action::make('edit')
                ->label('Modifier')
                ->color('gray')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn () => TemplateResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    /**
     * Test vers une des adresses de EMAIL_TEST_RECIPIENTS uniquement, par la file d'attente.
     */
    public function sendTest(string $recipient): void
    {
        $company = $this->company();

        if ($company === null || ! in_array($recipient, config('prospection.emails.test_recipients'), true) || SuppressionEntry::blocks($recipient)) {
            Notification::make()->title('Envoi de test refusé')->danger()->send();

            return;
        }

        // Adresse de test à la place de celle du prospect : son lien de désinscription vise ta boîte.
        $contact = new Contact(['email' => $recipient, 'source' => $this->contact()?->source]);
        $email = app(TemplateRenderer::class)->render($this->record, $company, $contact, 'test');

        Mail::to($recipient)->queue((new ProspectMail($email, isTest: true))->onQueue('default'));

        Notification::make()
            ->title('Test mis en file')
            ->body("Envoi vers {$recipient} dans quelques instants (worker requis).")
            ->success()
            ->send();
    }

    public function rendered(): ?RenderedEmail
    {
        $company = $this->company();

        return $company ? app(TemplateRenderer::class)->render($this->record, $company, $this->contact(), preview: true) : null;
    }

    private function company(): ?Company
    {
        return filled($this->data['company_id'] ?? null) ? Company::with('website')->find($this->data['company_id']) : null;
    }

    private function contact(): ?Contact
    {
        $id = $this->data['contact_id'] ?? null;

        return filled($id) ? Contact::query()->where('company_id', $this->data['company_id'] ?? null)->find($id) : null;
    }

    /**
     * Meilleur prospect qui correspond à l'angle du template (sans site / site obsolète), avec un email si possible.
     */
    private function defaultCompany(): ?Company
    {
        $states = $this->record->angle === TemplateAngle::OutdatedWebsite
            ? [WebsiteState::Outdated]
            : [WebsiteState::None, WebsiteState::SocialOnly, WebsiteState::Directory, WebsiteState::Offline, WebsiteState::Parking];

        $query = fn () => Company::query()->toProcess()->whereHas('website', fn (Builder $query) => $query->whereIn('state', $states))->orderByDesc('score');

        return $query()->has('contacts')->first() ?? $query()->first() ?? Company::query()->orderByDesc('score')->first();
    }

    private static function companyLabel(Company $company): string
    {
        return trim($company->name.' — '.$company->city.($company->score !== null ? " ({$company->score}/100)" : ''));
    }
}
