<?php

namespace App\Filament\Imports;

use App\Enums\ActivityType;
use App\Enums\DataSource;
use App\Jobs\AnalyzeWebsite;
use App\Jobs\EnrichCompany;
use App\Models\Company;
use App\Services\CompanyScorer;
use App\Services\ContactRecorder;
use App\Support\Domain;
use App\Support\Phone;
use Carbon\Carbon;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Throwable;

/**
 * Import CSV d'entreprises (mission M3), exécuté en tâche de fond par Filament.
 *
 * Dédoublonnage : même SIREN, puis même domaine (site ou email pro) ou même téléphone.
 * Une entreprise déjà connue est complétée (champs vides seulement), jamais recréée ni écrasée.
 * Une adresse présente sur la liste d'opposition est importée, mais l'entreprise passe en
 * « Ne pas contacter ».
 */
class CompanyImporter extends Importer
{
    protected static ?string $model = Company::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->label("Nom de l'entreprise")
                ->requiredMapping()
                ->guess(['nom de l’entreprise', 'nom', 'raison sociale', 'raison_sociale', 'denomination', 'dénomination', 'entreprise', 'société', 'societe', 'company'])
                ->castStateUsing(fn (?string $state) => self::clean($state))
                ->rules(['required', 'max:255'])
                ->fillRecordUsing(self::fillIfEmpty('name'))
                ->example('Plomberie Martin'),
            ImportColumn::make('siren')
                ->label('SIREN')
                ->validationAttribute('SIREN')
                ->guess(['siren', 'n° siren', 'numero siren', 'siret'])
                ->castStateUsing(fn (?string $state) => self::siren($state))
                ->rules(['nullable', 'regex:/^\d{9}$/'])
                ->fillRecordUsing(self::fillIfEmpty('siren'))
                ->example('123456789'),
            ImportColumn::make('naf_code')
                ->label('Code NAF / APE')
                ->validationAttribute('code NAF')
                ->guess(['naf', 'code naf', 'ape', 'code ape', 'activite principale', 'activité principale'])
                ->castStateUsing(fn (?string $state) => self::naf($state))
                ->rules(['nullable', 'regex:/^\d{2}\.\d{2}[A-Z]$/'])
                ->fillRecordUsing(self::fillIfEmpty('naf_code'))
                ->example('43.22A'),
            ImportColumn::make('address')
                ->label('Adresse')
                ->guess(['adresse', 'rue', 'address'])
                ->castStateUsing(fn (?string $state) => self::clean($state))
                ->rules(['nullable', 'max:255'])
                ->fillRecordUsing(self::fillIfEmpty('address'))
                ->example('12 rue de la République'),
            ImportColumn::make('postal_code')
                ->label('Code postal')
                ->guess(['code postal', 'cp', 'code_postal', 'postal code', 'zip'])
                ->castStateUsing(fn (?string $state) => self::postalCode($state))
                ->rules(['nullable', 'regex:/^\d{5}$/'])
                ->fillRecordUsing(self::fillIfEmpty('postal_code'))
                ->example('71100'),
            ImportColumn::make('city')
                ->label('Ville')
                ->guess(['ville', 'commune', 'localite', 'localité', 'city'])
                ->castStateUsing(fn (?string $state) => self::clean($state))
                ->rules(['nullable', 'max:255'])
                ->fillRecordUsing(self::fillIfEmpty('city'))
                ->example('Chalon-sur-Saône'),
            ImportColumn::make('phone')
                ->label('Téléphone')
                ->guess(['telephone', 'téléphone', 'tel', 'tél', 'phone', 'portable'])
                ->castStateUsing(fn (?string $state) => Phone::pickBusiness($state)) // plusieurs numéros : le fixe est gardé
                ->rules(['nullable', 'regex:/^(0\d{9}|\+\d{8,15})$/'])
                ->fillRecordUsing(self::fillIfEmpty('phone'))
                ->example('03 85 12 34 56'),
            ImportColumn::make('website')
                ->label('Site web')
                ->guess(['site', 'site web', 'site internet', 'website', 'url', 'web'])
                ->castStateUsing(fn (?string $state) => self::clean($state))
                ->rules(['nullable', 'max:255', 'regex:#^(https?://)?([a-z0-9-]+\.)+[a-z]{2,}(:\d+)?(/\S*)?$#i'])
                ->fillRecordUsing(fn () => null) // enregistré après la sauvegarde (afterSave)
                ->example('https://www.plomberie-martin.fr'),
            ImportColumn::make('email')
                ->label('Email')
                ->guess(['email', 'e-mail', 'mail', 'courriel', 'adresse email', 'adresse mail'])
                ->castStateUsing(fn (?string $state) => filled($state) ? mb_strtolower(trim($state)) : null)
                ->rules(['nullable', 'email:rfc', 'max:255'])
                ->fillRecordUsing(fn () => null) // enregistré après la sauvegarde (afterSave)
                ->example('contact@plomberie-martin.fr'),
            ImportColumn::make('founded_on')
                ->label('Date de création')
                ->guess(['date de creation', 'date de création', 'date creation', 'creation', 'création', 'date_creation'])
                ->castStateUsing(fn (?string $state) => self::date($state))
                ->rules(['bail', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'])
                ->fillRecordUsing(self::fillIfEmpty('founded_on'))
                ->example('2015-03-01'),
            ImportColumn::make('headcount_range')
                ->label("Tranche d'effectif (INSEE)")
                ->guess(['tranche d’effectif (insee)', 'effectif', 'tranche effectif', 'tranche d\'effectif', 'tranche_effectif_salarie'])
                ->castStateUsing(fn (?string $state) => self::clean($state))
                ->rules(['nullable', 'regex:/^(NN|\d{2})$/'])
                ->fillRecordUsing(self::fillIfEmpty('headcount_range'))
                ->example('02'),
        ];
    }

    /**
     * Retrouve une entreprise déjà connue : SIREN d'abord, puis domaine ou téléphone.
     */
    public function resolveRecord(): Company
    {
        $siren = $this->data['siren'] ?? null;

        if ($siren && ($company = Company::query()->where('siren', $siren)->first())) {
            return $company;
        }

        $domain = Domain::fromUrl($this->data['website'] ?? null) ?? Domain::fromEmail($this->data['email'] ?? null);
        $phone = $this->data['phone'] ?? null;

        if ($domain || $phone) {
            $match = Company::query()
                ->where(function (Builder $query) use ($domain, $phone) {
                    if ($domain) {
                        $query->orWhereHas('website', fn (Builder $query) => $query->where('domain', $domain))
                            ->orWhereHas('contacts', fn (Builder $query) => $query->where('email', 'like', '%@'.$domain));
                    }

                    if ($phone) {
                        $query->orWhere('phone', $phone);
                    }
                })
                // Deux SIREN différents désignent deux entreprises distinctes, même si elles partagent un numéro.
                ->when($siren, fn (Builder $query) => $query->whereNull('siren'))
                ->oldest('id')
                ->first();

            if ($match) {
                return $match;
            }
        }

        return new Company;
    }

    protected function beforeCreate(): void
    {
        $this->record->source = DataSource::CsvImport;
    }

    protected function beforeSave(): void
    {
        if (blank($this->record->department) && filled($this->record->postal_code)) {
            $this->record->department = self::department($this->record->postal_code);
        }
    }

    protected function afterCreate(): void
    {
        $this->record->activities()->create([
            'type' => ActivityType::Note,
            'content' => 'Ajoutée par import CSV ('.$this->import->file_name.').',
            'occurred_at' => now(),
        ]);
    }

    protected function afterSave(): void
    {
        $websiteAdded = $this->saveWebsite();
        $this->saveContact();

        // M5 : nouvelle entreprise → enrichissement puis analyse du site ;
        // entreprise connue dont le site vient d'être ajouté → analyse de ce site.
        if ($this->record->wasRecentlyCreated) {
            EnrichCompany::dispatch($this->record);
        } elseif ($websiteAdded) {
            AnalyzeWebsite::dispatch($this->record);
        }

        // M6 : score provisoire, affiné après l'analyse du site.
        app(CompanyScorer::class)->refresh($this->record->fresh());
    }

    private function saveWebsite(): bool
    {
        $url = $this->data['website'] ?? null;

        if (blank($url) || $this->record->website()->exists()) {
            return false;
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'http://'.$url;
        }

        $this->record->website()->create(['url' => $url]);

        return true;
    }

    private function saveContact(): void
    {
        $email = $this->data['email'] ?? null;

        if (blank($email)) {
            return;
        }

        ContactRecorder::record($this->record, $email, DataSource::CsvImport);
    }

    /**
     * Messages du rapport d'erreurs rédigés pour la personne qui corrige le fichier.
     */
    public function getValidationMessages(): array
    {
        return [
            'siren.regex' => 'Le SIREN doit contenir 9 chiffres (ou un SIRET de 14 chiffres).',
            'naf_code.regex' => 'Le code NAF doit avoir la forme 43.22A.',
            'postal_code.regex' => 'Le code postal doit contenir 5 chiffres.',
            'phone.regex' => 'Le téléphone doit contenir 10 chiffres (ou commencer par +).',
            'website.regex' => 'L’adresse du site est invalide.',
            'founded_on.date_format' => 'La date de création doit être au format JJ/MM/AAAA ou AAAA-MM-JJ.',
            'founded_on.before_or_equal' => 'La date de création ne peut pas être dans le futur.',
            'headcount_range.regex' => 'La tranche d’effectif doit être un code INSEE à 2 chiffres (ex. 02).',
        ];
    }

    public static function getCompletedNotificationTitle(Import $import): string
    {
        return 'Import CSV terminé';
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Import de « '.$import->file_name.' » terminé : '
            .Number::format($import->successful_rows).' '.($import->successful_rows > 1 ? 'lignes importées' : 'ligne importée')
            .' sur '.Number::format($import->total_rows).'.';

        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.($failed > 1 ? 'lignes en erreur' : 'ligne en erreur')
                .' : téléchargez le rapport pour voir le détail.';
        }

        return $body;
    }

    /**
     * Remplit l'attribut seulement s'il est vide : un import ne remplace jamais une donnée existante.
     */
    private static function fillIfEmpty(string $attribute): \Closure
    {
        return function (Company $record, mixed $state) use ($attribute): void {
            if (blank($state) || filled($record->getAttribute($attribute))) {
                return;
            }

            $record->setAttribute($attribute, $state);
        };
    }

    private static function clean(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    private static function siren(?string $value): ?string
    {
        $digits = preg_replace('/\s+/', '', (string) $value);

        // Un SIRET (14 chiffres) contient le SIREN dans ses 9 premiers chiffres.
        if (preg_match('/^\d{14}$/', $digits)) {
            return substr($digits, 0, 9);
        }

        return $digits === '' ? null : $digits;
    }

    private static function naf(?string $value): ?string
    {
        $value = mb_strtoupper(str_replace(' ', '', (string) $value));

        if (preg_match('/^(\d{2})\.?(\d{2})([A-Z])$/', $value, $matches)) {
            return "{$matches[1]}.{$matches[2]}{$matches[3]}";
        }

        return $value === '' ? null : $value;
    }

    private static function postalCode(?string $value): ?string
    {
        $digits = preg_replace('/\s+/', '', (string) $value);

        // Excel supprime souvent le zéro initial : « 1000 » → « 01000 ».
        if (preg_match('/^\d{4}$/', $digits)) {
            return '0'.$digits;
        }

        return $digits === '' ? null : $digits;
    }

    private static function department(string $postalCode): ?string
    {
        if (! preg_match('/^\d{5}$/', $postalCode)) {
            return null;
        }

        return match (true) {
            str_starts_with($postalCode, '97') => substr($postalCode, 0, 3),
            str_starts_with($postalCode, '20') => (int) $postalCode < 20200 ? '2A' : '2B',
            default => substr($postalCode, 0, 2),
        };
    }

    /**
     * Accepte « 2015-03-01 », « 01/03/2015 » et « 01-03-2015 » ; une date illisible est laissée
     * telle quelle pour que la validation signale la ligne.
     */
    private static function date(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return $value;
    }
}
