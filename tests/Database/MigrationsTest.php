<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\SuppressionEntry;
use Illuminate\Support\Facades\Schema;

/*
 * Ce test vit hors de tests/Feature : migrate:fresh ne peut pas tourner
 * dans la transaction ouverte par RefreshDatabase.
 */

it('passe migrate:fresh --seed sans erreur', function () {
    $this->artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

    foreach (['companies', 'websites', 'contacts', 'searches', 'templates', 'sequences', 'sequence_steps',
        'campaigns', 'emails', 'suppression_list', 'activities'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("La table {$table} manque");
    }

    expect(Company::count())->toBeGreaterThan(0)
        ->and(Contact::count())->toBeGreaterThan(0)
        ->and(SuppressionEntry::count())->toBeGreaterThan(0);
});
