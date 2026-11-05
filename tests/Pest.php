<?php

use App\Services\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeDnsResolver;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Tous les tests « Feature » utilisent une base SQLite en mémoire remise à
| zéro à chaque test, et aucune requête HTTP réelle ne peut sortir.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        // Aucune requête DNS réelle non plus (vérification MX, devinette de domaine).
        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
    })
    ->in('Feature');

// Tests de schéma qui gèrent eux-mêmes la base (migrate:fresh), sans transaction.
pest()->extend(TestCase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
    })
    ->in('Database');
