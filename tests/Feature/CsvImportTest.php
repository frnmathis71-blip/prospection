<?php

use App\Enums\ContactType;
use App\Enums\ContactVerification;
use App\Enums\DataSource;
use App\Enums\ProspectStatus;
use App\Filament\Imports\CompanyImporter;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Models\Website;
use Filament\Actions\ImportAction;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

const CSV_COLUMNS = ['name', 'siren', 'naf_code', 'address', 'postal_code', 'city', 'phone', 'website', 'email', 'founded_on', 'headcount_range'];

/**
 * Fait passer des lignes CSV par le vrai job d'import Filament (celui qui tourne en tâche de fond).
 *
 * @param  array<int, array<string, string>>  $rows  lignes indexées par en-tête de colonne CSV
 */
function runImport(array $rows, string $fileName = 'prospects.csv'): Import
{
    $user = User::first() ?? User::factory()->withAppAuthentication()->create();

    $import = Import::create([
        'user_id' => $user->id,
        'file_name' => $fileName,
        'file_path' => 'imports/'.$fileName,
        'importer' => CompanyImporter::class,
        'total_rows' => count($rows),
    ]);

    (new ImportCsv($import, $rows, array_combine(CSV_COLUMNS, CSV_COLUMNS)))->handle();

    return $import->refresh();
}

/** Ligne CSV complète et valide ; les clés absentes restent vides. */
function csvRow(array $values = []): array
{
    return array_merge(array_fill_keys(CSV_COLUMNS, ''), $values);
}

it('crée 100 entreprises à partir de 100 lignes valides', function () {
    $rows = collect(range(1, 100))->map(fn (int $i) => csvRow([
        'name' => "Entreprise {$i}",
        'siren' => (string) (100_000_000 + $i),
        'postal_code' => '71100',
        'city' => 'Chalon-sur-Saône',
        'phone' => sprintf('03 85 %02d %02d %02d', intdiv($i, 100), $i % 100, 10),
        'website' => "https://www.entreprise-{$i}.example",
        'email' => "contact@entreprise-{$i}.example",
    ]))->all();

    $import = runImport($rows);

    expect(Company::count())->toBe(100)
        ->and(Website::count())->toBe(100)
        ->and(Contact::count())->toBe(100)
        ->and($import->successful_rows)->toBe(100)
        ->and($import->getFailedRowsCount())->toBe(0);
});

it('normalise les données importées', function () {
    runImport([csvRow([
        'name' => '  Plomberie   Martin ',
        'siren' => '123 456 789',
        'naf_code' => '4322a',
        'postal_code' => '1000',
        'phone' => '+33 (0)3 85 12 34 56',
        'website' => 'www.plomberie-martin.example/accueil',
        'email' => 'Contact@Plomberie-Martin.example',
        'founded_on' => '01/03/2015',
    ])]);

    $company = Company::sole();

    expect($company->name)->toBe('Plomberie Martin')
        ->and($company->siren)->toBe('123456789')
        ->and($company->naf_code)->toBe('43.22A')
        ->and($company->postal_code)->toBe('01000')
        ->and($company->department)->toBe('01')
        ->and($company->phone)->toBe('0385123456')
        ->and($company->founded_on->format('Y-m-d'))->toBe('2015-03-01')
        ->and($company->website->url)->toBe('http://www.plomberie-martin.example/accueil')
        ->and($company->website->domain)->toBe('plomberie-martin.example')
        ->and($company->contacts->sole()->email)->toBe('contact@plomberie-martin.example');
});

describe('dédoublonnage', function () {
    beforeEach(function () {
        $this->existing = Company::factory()->create([
            'name' => 'Plomberie Martin',
            'siren' => '123456789',
            'phone' => '0385123456',
            'city' => null,
        ]);
        Website::factory()->for($this->existing)->create(['url' => 'https://www.plomberie-martin.example']);
    });

    it('fusionne une ligne au même SIREN, sans écraser les données existantes', function () {
        runImport([csvRow(['name' => 'PLOMBERIE MARTIN SARL', 'siren' => '123 456 789', 'city' => 'Mâcon'])]);

        $company = Company::sole();

        expect($company->is($this->existing))->toBeTrue()
            ->and($company->name)->toBe('Plomberie Martin') // déjà renseigné : conservé
            ->and($company->city)->toBe('Mâcon'); // vide : complété
    });

    it('fusionne une ligne sans SIREN au même domaine de site', function () {
        runImport([csvRow(['name' => 'Martin Plomberie', 'website' => 'http://plomberie-martin.example/contact'])]);

        expect(Company::count())->toBe(1)
            ->and(Website::count())->toBe(1);
    });

    it('fusionne une ligne dont l’email pro est sur le domaine du site', function () {
        runImport([csvRow(['name' => 'Martin', 'email' => 'devis@plomberie-martin.example'])]);

        expect(Company::count())->toBe(1)
            ->and($this->existing->contacts()->sole()->email)->toBe('devis@plomberie-martin.example');
    });

    it('fusionne une ligne au même téléphone, quel que soit son format', function () {
        runImport([csvRow(['name' => 'Martin', 'phone' => '+33 3 85 12 34 56'])]);

        expect(Company::count())->toBe(1);
    });

    it('ne fusionne pas deux SIREN différents qui partagent un téléphone', function () {
        runImport([csvRow(['name' => 'Autre société', 'siren' => '987654321', 'phone' => '03 85 12 34 56'])]);

        expect(Company::count())->toBe(2);
    });

    it('ne fusionne pas deux entreprises sur un même réseau social ou une messagerie grand public', function () {
        Website::factory()->for(Company::factory())->create(['url' => 'https://www.facebook.com/boulangerie-a']);

        runImport([
            csvRow(['name' => 'Boulangerie B', 'website' => 'https://www.facebook.com/boulangerie-b']),
            csvRow(['name' => 'Coiffure C', 'email' => 'coiffure.c@gmail.com']),
            csvRow(['name' => 'Coiffure D', 'email' => 'coiffure.d@gmail.com']),
        ]);

        expect(Company::count())->toBe(5);
    });

    it('dédoublonne aussi les lignes répétées dans un même fichier', function () {
        runImport([
            csvRow(['name' => 'Électricité Dupont', 'siren' => '555444333']),
            csvRow(['name' => 'Electricite Dupont', 'siren' => '555 444 333', 'email' => 'contact@dupont.example']),
        ]);

        expect(Company::where('siren', '555444333')->count())->toBe(1);
    });

    it('ne crée aucun doublon quand on réimporte le même fichier', function () {
        $rows = [
            csvRow(['name' => 'A', 'siren' => '111111111', 'email' => 'contact@a.example', 'website' => 'a.example']),
            csvRow(['name' => 'B', 'phone' => '0380000001']),
            csvRow(['name' => 'C', 'website' => 'https://c.example']),
        ];

        runImport($rows);
        $counts = [Company::count(), Contact::count(), Website::count()];
        runImport($rows);

        expect([Company::count(), Contact::count(), Website::count()])->toBe($counts)
            ->and($counts)->toBe([4, 1, 3]);
    });
});

it('liste les lignes invalides dans le rapport sans bloquer l’import', function () {
    $import = runImport([
        csvRow(['name' => 'Valide 1', 'siren' => '200000001']),
        csvRow(['name' => '', 'siren' => '200000002']),
        csvRow(['name' => 'Email invalide', 'email' => 'pas-un-email']),
        csvRow(['name' => 'SIREN invalide', 'siren' => '12345']),
        csvRow(['name' => 'Date invalide', 'founded_on' => '31/02/2020']),
        csvRow(['name' => 'Valide 2', 'siren' => '200000003']),
    ]);

    expect(Company::pluck('name')->sort()->values()->all())->toBe(['Valide 1', 'Valide 2'])
        ->and($import->successful_rows)->toBe(2)
        ->and($import->getFailedRowsCount())->toBe(4);

    $failures = $import->failedRows()->get();

    expect($failures->pluck('data.name')->all())->toBe(['', 'Email invalide', 'SIREN invalide', 'Date invalide'])
        ->and($failures->pluck('validation_error')->all())->toBe([
            "Le champ nom de l'entreprise est obligatoire.",
            'Le champ email doit être une adresse email valide.',
            'Le SIREN doit contenir 9 chiffres (ou un SIRET de 14 chiffres).',
            'La date de création doit être au format JJ/MM/AAAA ou AAAA-MM-JJ.',
        ]);
});

it('importe un email de la liste d’opposition mais marque l’entreprise « Ne pas contacter »', function () {
    SuppressionEntry::factory()->create(['email' => 'contact@bloque.example']);
    SuppressionEntry::factory()->domain('domaine-bloque.example')->create();

    runImport([
        csvRow(['name' => 'Bloquée', 'email' => 'CONTACT@bloque.example']),
        csvRow(['name' => 'Bloquée par domaine', 'email' => 'jean@domaine-bloque.example']),
        csvRow(['name' => 'Libre', 'email' => 'contact@libre.example']),
    ]);

    expect(Contact::count())->toBe(3)
        ->and(Company::firstWhere('name', 'Bloquée')->status)->toBe(ProspectStatus::DoNotContact)
        ->and(Company::firstWhere('name', 'Bloquée par domaine')->status)->toBe(ProspectStatus::DoNotContact)
        ->and(Company::firstWhere('name', 'Libre')->status)->toBe(ProspectStatus::New);
});

it('enregistre la source et la date de collecte de chaque contact', function () {
    $this->travelTo('2026-11-04 10:00:00');

    runImport([
        csvRow(['name' => 'A', 'email' => 'contact@a.example']),
        csvRow(['name' => 'B', 'email' => 'jean.dupont@b.example']),
        csvRow(['name' => 'C', 'email' => 'artisan.c@gmail.com']),
    ]);

    expect(Contact::all())->each(fn ($contact) => $contact
        ->source->toBe(DataSource::CsvImport)
        ->collected_at->toDateTimeString()->toBe('2026-11-04 10:00:00')
        ->verification->toBe(ContactVerification::Pending));

    expect(Contact::pluck('type', 'email')->all())->toBe([
        'contact@a.example' => ContactType::Generic,
        'jean.dupont@b.example' => ContactType::Nominative,
        'artisan.c@gmail.com' => ContactType::Personal,
    ])->and(Company::pluck('source')->unique()->all())->toBe([DataSource::CsvImport]);
});

it('garde la source d’origine d’une entreprise déjà connue', function () {
    Company::factory()->create(['siren' => '123456789', 'source' => DataSource::RechercheEntreprises]);

    runImport([csvRow(['name' => 'X', 'siren' => '123456789', 'email' => 'contact@x.example'])]);

    expect(Company::sole()->source)->toBe(DataSource::RechercheEntreprises)
        ->and(Contact::sole()->source)->toBe(DataSource::CsvImport);
});

it('lance l’import en tâche de fond depuis la liste des entreprises, avec choix des colonnes', function () {
    Bus::fake();
    $this->actingAs(User::factory()->withAppAuthentication()->create());

    $csv = "Raison sociale;SIREN;Ville;Courriel\nPlomberie Martin;123456789;Mâcon;contact@martin.example\n";

    Livewire::test(ListCompanies::class)
        ->callAction(ImportAction::class, data: [
            'file' => UploadedFile::fake()->createWithContent('prospects.csv', $csv),
            'columnMap' => ['name' => 'Raison sociale', 'siren' => 'SIREN', 'city' => 'Ville', 'email' => 'Courriel'],
        ])
        ->assertHasNoActionErrors();

    Bus::assertBatched(fn ($batch) => $batch->jobs->first() instanceof ImportCsv);

    expect(Import::sole())
        ->file_name->toBe('prospects.csv')
        ->total_rows->toBe(1)
        ->and(Company::count())->toBe(0); // rien n'est importé tant que le worker n'a pas traité la file
});

it('propose les colonnes attendues avec leurs libellés français', function () {
    $labels = collect(CompanyImporter::getColumns())->map->getLabel()->all();

    expect($labels)->toContain("Nom de l'entreprise", 'SIREN', 'Code postal', 'Ville', 'Téléphone', 'Site web', 'Email');
});

it('importe une ligne contenant deux numéros en gardant le fixe', function () {
    $import = runImport([
        csvRow(['name' => 'Domaine des Essais', 'postal_code' => '71390', 'phone' => '03 85 00 11 22 / 06 00 11 22 33']),
        csvRow(['name' => 'Gîte des Essais', 'postal_code' => '89200', 'phone' => '06 00 44 55 66 / 06 00 77 88 99']),
    ]);

    expect($import->getFailedRowsCount())->toBe(0)
        ->and(Company::pluck('phone', 'name')->all())->toBe([
            'Domaine des Essais' => '0385001122',
            'Gîte des Essais' => '0600445566',
        ]);
});

it('reconnaît automatiquement les colonnes d’un rapport d’erreurs réimporté', function () {
    $headers = ["Nom de l'entreprise", 'SIREN', 'Code NAF / APE', 'Adresse', 'Code postal', 'Ville', 'Téléphone', 'Site web', 'Email', 'Date de création', "Tranche d'effectif (INSEE)", 'error'];

    foreach (CompanyImporter::getColumns() as $column) {
        expect(array_map(Str::lower(...), $headers))->toContain($column->getGuesses()[0]);
    }
});
