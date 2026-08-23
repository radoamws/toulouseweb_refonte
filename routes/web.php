<?php

use App\Http\Controllers\ClickTrackingController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class.'@index')->name('home');

// Tracking de clics générique (voir TECHNICAL_DOCUMENTATION.md §9) —
// appelable depuis n'importe quel composant public. Throttle pour éviter
// l'abus, pas de CSRF requis (appelé aussi depuis des liens sortants).
Route::post('/track-click', ClickTrackingController::class)
    ->middleware('throttle:60,1')
    ->name('track-click');

// Les routes publiques par domaine (annuaire, agenda, cinéma, annonces,
// actualités, contact) seront ajoutées au fil des phases 6 à 10.
