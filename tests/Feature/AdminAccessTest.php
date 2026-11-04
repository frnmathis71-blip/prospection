<?php

use App\Models\User;
use App\Support\TwoFactor;
use Filament\Pages\Dashboard;

it('redirige un visiteur non connecté vers la connexion', function (string $uri) {
    $this->get($uri)->assertRedirect(route('filament.admin.auth.login'));
})->with([
    'tableau de bord' => '/admin',
    'profil' => '/admin/profile',
    'Horizon' => '/horizon',
    'Horizon (tableau de bord)' => '/horizon/dashboard',
    'Horizon (jobs échoués)' => '/horizon/failed',
]);

it("n'expose aucune page publique : l'accueil mène à la connexion", function () {
    $this->get('/')->assertRedirect('/admin');

    $this->followingRedirects()
        ->get('/')
        ->assertOk()
        ->assertSee('Codenyr Prospection');

    expect(url()->current())->toBe(route('filament.admin.auth.login'));
});

it("bloque l'API interne de Horizon pour un visiteur non connecté", function () {
    $this->getJson('/horizon/api/stats')->assertUnauthorized();
});

it("affiche la page de connexion sans proposer d'inscription", function () {
    $this->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertDontSee('/admin/register');

    expect(Route::has('filament.admin.auth.register'))->toBeFalse()
        ->and(Route::has('filament.admin.auth.password-reset.request'))->toBeFalse();
});

it('donne accès au tableau de bord Filament à un utilisateur connecté avec 2FA', function () {
    $user = User::factory()->withAppAuthentication()->create();

    $this->actingAs($user)
        ->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('Codenyr Prospection');
});

it("impose la configuration de la 2FA à un utilisateur qui ne l'a pas activée", function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(Dashboard::getUrl())
        ->assertRedirect();
});

it('ouvre Horizon à un utilisateur connecté avec 2FA', function () {
    $user = User::factory()->withAppAuthentication()->create();

    $this->actingAs($user)->get('/horizon')->assertOk();
});

it('refuse Horizon à un utilisateur connecté sans 2FA, même en local', function () {
    app()->detectEnvironment(fn () => 'local');
    $user = User::factory()->create();

    $this->actingAs($user)->get('/horizon')->assertForbidden();
});

/**
 * Redémarre l'application avec AUTH_TWO_FACTOR_REQUIRED=false, comme le ferait
 * le .env (Filament fixe la règle 2FA au démarrage du panneau).
 */
function bootWithTwoFactorDisabled(): void
{
    putenv('AUTH_TWO_FACTOR_REQUIRED=false');
    $_ENV['AUTH_TWO_FACTOR_REQUIRED'] = $_SERVER['AUTH_TWO_FACTOR_REQUIRED'] = 'false';

    test()->refreshApplication();
    test()->artisan('migrate');
}

afterEach(function () {
    putenv('AUTH_TWO_FACTOR_REQUIRED=true');
    $_ENV['AUTH_TWO_FACTOR_REQUIRED'] = $_SERVER['AUTH_TWO_FACTOR_REQUIRED'] = 'true';
});

it('laisse entrer sans 2FA quand elle est coupée en local', function () {
    bootWithTwoFactorDisabled();
    $user = User::factory()->create();

    $this->actingAs($user)->get(Dashboard::getUrl())->assertOk();
    $this->actingAs($user)->get('/horizon')->assertOk();
});

it('impose toujours la 2FA en production, même si elle est coupée', function () {
    config(['auth.two_factor_required' => false]);
    app()->detectEnvironment(fn () => 'production');

    expect(TwoFactor::isRequired())->toBeTrue();

    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
});

it('garde la connexion obligatoire quand la 2FA est coupée', function () {
    bootWithTwoFactorDisabled();

    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    $this->get('/horizon')->assertRedirect(route('filament.admin.auth.login'));
});

it('chiffre le secret 2FA en base', function () {
    $user = User::factory()->withAppAuthentication()->create();

    $raw = DB::table('users')->where('id', $user->id)->value('app_authentication_secret');

    expect($raw)->not->toBe('JBSWY3DPEHPK3PXP')
        ->and($user->fresh()->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP');
});
