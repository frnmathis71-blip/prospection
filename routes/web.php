<?php

use App\Http\Controllers\UnsubscribeController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// Outil personnel : aucune page publique, l'accueil renvoie vers l'administration.
Route::redirect('/', '/admin');

// Seule page publique : désinscription en un clic depuis un email (lien signé, non devinable).
// POST = désinscription « One-Click » des messageries (en-tête List-Unsubscribe-Post).
Route::match(['get', 'post'], '/desinscription', UnsubscribeController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('unsubscribe');
