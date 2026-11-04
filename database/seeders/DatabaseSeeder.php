<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Aucun utilisateur n'est créé ici (pas de compte au mot de passe connu) :
     * l'unique utilisateur se crée avec « php artisan make:filament-user ».
     *
     * Les données de démonstration (fictives) ne sont jamais chargées en production.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $this->call(DemoSeeder::class);
    }
}
