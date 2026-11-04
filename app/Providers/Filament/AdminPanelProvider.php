<?php

namespace App\Providers\Filament;

use App\Support\TwoFactor;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Codenyr Prospection')
            // Connexion seule : ni inscription, ni réinitialisation de mot de passe par email.
            ->login()
            // Page profil : gestion de la 2FA (codes de secours, désactivation, mot de passe).
            ->profile()
            // Notifications en base : fin des imports CSV et autres tâches de fond.
            ->databaseNotifications()
            ->databaseNotificationsPolling('15s')
            // Double authentification par application TOTP, avec codes de secours.
            // Obligatoire sauf si coupée en local (AUTH_TWO_FACTOR_REQUIRED=false) ;
            // toujours imposée en production. Activable à tout moment depuis le profil.
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->brandName('Codenyr Prospection')
                    ->recoverable(),
            ], isRequired: fn (): bool => TwoFactor::isRequired())
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Tableau de bord : état de la file d'attente et suivi des imports (app/Filament/Widgets).
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
