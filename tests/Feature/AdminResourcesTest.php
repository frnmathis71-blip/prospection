<?php

use App\Enums\ProspectStatus;
use App\Enums\SuppressionReason;
use App\Enums\WebsiteState;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Filament\Resources\SuppressionEntries\Pages\ManageSuppressionEntries;
use App\Filament\Resources\SuppressionEntries\SuppressionEntryResource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SuppressionEntry;
use App\Models\User;
use App\Models\Website;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withAppAuthentication()->create());
});

it('affiche les pages entreprises, contacts et liste d’opposition', function (string $url) {
    $this->get($url)->assertOk();
})->with([
    'entreprises' => fn () => CompanyResource::getUrl('index'),
    'contacts' => fn () => ContactResource::getUrl('index'),
    "liste d'opposition" => fn () => SuppressionEntryResource::getUrl('index'),
]);

it('affiche la fiche d’une entreprise avec son site et ses contacts', function () {
    $company = Company::factory()->create();
    Website::factory()->outdated()->for($company)->create();
    $contact = Contact::factory()->for($company)->create();

    Livewire::test(ViewCompany::class, ['record' => $company->getKey()])
        ->assertOk()
        ->assertSee($company->name)
        ->assertSee($contact->email)
        ->assertSee("Le site n'est pas sécurisé (pas de HTTPS)");
});

it('trie les entreprises par score décroissant', function () {
    $low = Company::factory()->create(['score' => 10]);
    $high = Company::factory()->create(['score' => 90]);

    Livewire::test(ListCompanies::class)->assertCanSeeTableRecords([$high, $low], inOrder: true);
});

it('filtre les entreprises par statut, département, état du site, email et score', function () {
    $target = Company::factory()->create(['status' => ProspectStatus::Qualified, 'department' => '71', 'score' => 80]);
    Website::factory()->none()->for($target)->create();

    $otherStatus = Company::factory()->create(['status' => ProspectStatus::Won, 'department' => '71', 'score' => 80]);
    $otherDepartment = Company::factory()->create(['department' => '21', 'score' => 80]);
    $withSite = Company::factory()->create(['department' => '71', 'score' => 80]);
    Website::factory()->for($withSite)->create();
    $withEmail = Company::factory()->has(Contact::factory())->create(['department' => '71', 'score' => 80]);
    $lowScore = Company::factory()->create(['department' => '71', 'score' => 20]);

    Livewire::test(ListCompanies::class)
        ->filterTable('status', [ProspectStatus::Qualified->value])
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$otherStatus])
        ->resetTableFilters()
        ->filterTable('department', ['71'])
        ->assertCanNotSeeTableRecords([$otherDepartment])
        ->resetTableFilters()
        ->filterTable('website_state', [WebsiteState::None->value])
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$withSite])
        ->resetTableFilters()
        ->filterTable('has_email', false)
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$withEmail])
        ->resetTableFilters()
        ->filterTable('score', ['min' => 50])
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$lowScore]);
});

it('isole les entreprises au site obsolète', function () {
    $outdated = Website::factory()->outdated()->create()->company;
    $recent = Website::factory()->create()->company;
    $none = Website::factory()->none()->create()->company;

    Livewire::test(ListCompanies::class)
        ->filterTable('website_state', [WebsiteState::Outdated->value])
        ->assertCanSeeTableRecords([$outdated])
        ->assertCanNotSeeTableRecords([$recent, $none]);
});

it('affiche le lien du site obsolète dans la liste et sur la fiche', function () {
    $website = Website::factory()->outdated()->create(['url' => 'http://www.plomberie-martin.example/']);
    $company = $website->company;
    $none = Website::factory()->none()->create()->company;

    Livewire::test(ListCompanies::class)
        ->assertCanSeeTableRecords([$company, $none])
        ->assertSee('plomberie-martin.example')
        ->assertSeeHtml('href="http://www.plomberie-martin.example/"')
        ->searchTable('plomberie-martin')
        ->assertCanSeeTableRecords([$company])
        ->assertCanNotSeeTableRecords([$none]);

    expect(CompanyResource::shortUrl('https://www.plomberie-martin.example/'))->toBe('plomberie-martin.example')
        ->and(CompanyResource::shortUrl(null))->toBeNull();

    Livewire::test(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSeeHtml('href="http://www.plomberie-martin.example/"');
});

it('filtre les contacts présents sur la liste d’opposition', function () {
    $blocked = Contact::factory()->create(['email' => 'contact@bloque.example']);
    $blockedByDomain = Contact::factory()->create(['email' => 'jean@domaine-bloque.example']);
    $free = Contact::factory()->create(['email' => 'contact@libre.example']);
    SuppressionEntry::factory()->create(['email' => 'CONTACT@bloque.example']);
    SuppressionEntry::factory()->domain('domaine-bloque.example')->create();

    Livewire::test(ManageContacts::class)
        ->filterTable('suppressed', true)
        ->assertCanSeeTableRecords([$blocked, $blockedByDomain])
        ->assertCanNotSeeTableRecords([$free])
        ->filterTable('suppressed', false)
        ->assertCanSeeTableRecords([$free])
        ->assertCanNotSeeTableRecords([$blocked, $blockedByDomain]);
});

it('filtre la liste d’opposition par motif', function () {
    $unsubscribed = SuppressionEntry::factory()->create(['reason' => SuppressionReason::Unsubscribed]);
    $bounce = SuppressionEntry::factory()->create(['reason' => SuppressionReason::HardBounce]);

    Livewire::test(ManageSuppressionEntries::class)
        ->filterTable('reason', [SuppressionReason::HardBounce->value])
        ->assertCanSeeTableRecords([$bounce])
        ->assertCanNotSeeTableRecords([$unsubscribed]);
});

it('ne permet ni de modifier ni de supprimer une entrée de la liste d’opposition', function () {
    $entry = SuppressionEntry::factory()->create();

    Livewire::test(ManageSuppressionEntries::class)
        ->assertCanSeeTableRecords([$entry])
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');

    expect(SuppressionEntryResource::canEdit($entry))->toBeFalse()
        ->and(SuppressionEntryResource::canDelete($entry))->toBeFalse()
        ->and(SuppressionEntryResource::canDeleteAny())->toBeFalse();
});

it('ajoute une adresse à la liste d’opposition en minuscules', function () {
    Livewire::test(ManageSuppressionEntries::class)
        ->callAction('create', data: ['email' => 'Contact@Exemple.FR', 'reason' => SuppressionReason::Manual->value])
        ->assertHasNoActionErrors();

    expect(SuppressionEntry::first()->email)->toBe('contact@exemple.fr');
});
