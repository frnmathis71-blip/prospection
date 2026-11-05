<?php

namespace App\Filament\Pages;

use App\Enums\SuppressionReason;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SuppressionEntry;
use App\Services\PersonalDataExporter;
use App\Services\ProspectPurger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registre du traitement (mission M12) : description du fichier de prospection (finalité,
 * base légale, données, sources, durée, sécurité, droits) et export des données d'une personne.
 */
class Registry extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Registre RGPD';

    protected static ?string $title = 'Registre du traitement « Prospection »';

    protected static ?string $slug = 'registre';

    protected static ?int $navigationSort = 20;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.registry')->viewData(fn () => [
                'sender' => config('prospection.sender'),
                'retention' => (int) config('prospection.emails.retention_years'),
                'counts' => [
                    'companies' => Company::query()->count(),
                    'contacts' => Contact::query()->count(),
                    'suppressed' => SuppressionEntry::query()->count(),
                    'unsubscribed' => SuppressionEntry::query()->where('reason', SuppressionReason::Unsubscribed)->count(),
                    'to_purge' => app(ProspectPurger::class)->candidates()->count(),
                ],
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            self::exportAction(),
        ];
    }

    /**
     * Droit d'accès : toutes les données détenues sur une adresse, avec leur source (fichier JSON).
     */
    public static function exportAction(): Action
    {
        return Action::make('exportPerson')
            ->label('Exporter les données d’une personne')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->schema([
                TextInput::make('email')->label('Adresse email de la personne')->email()->required(),
            ])
            ->modalDescription('Fichier JSON : adresses et leur source, entreprise, emails envoyés, messages reçus, historique, liste d’opposition. À transmettre à la personne qui en fait la demande (réponse sous un mois).')
            ->modalSubmitActionLabel('Télécharger')
            ->action(fn (array $data) => self::download($data['email']));
    }

    public static function download(string $email): StreamedResponse
    {
        $json = json_encode(app(PersonalDataExporter::class)->export($email), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response()->streamDownload(fn () => print ($json), 'donnees-'.Str::slug($email).'.json', ['Content-Type' => 'application/json']);
    }
}
