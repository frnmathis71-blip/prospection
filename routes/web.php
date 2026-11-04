<?php

use Illuminate\Support\Facades\Route;

// Outil personnel : aucune page publique, l'accueil renvoie vers l'administration.
Route::redirect('/', '/admin');
