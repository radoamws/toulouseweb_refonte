<?php

use App\Http\Controllers\CinemaController;
use App\Http\Controllers\ClickTrackingController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ListingController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class.'@index')->name('home');

// Tracking de clics générique (voir TECHNICAL_DOCUMENTATION.md §9) —
// appelable depuis n'importe quel composant public. Throttle pour éviter
// l'abus, pas de CSRF requis (appelé aussi depuis des liens sortants).
Route::post('/track-click', ClickTrackingController::class)
    ->middleware('throttle:60,1')
    ->name('track-click');

// Annuaire (brief §5). `fiche` avant `{category}` pour éviter toute
// ambiguïté (chemins à segments différents, mais plus clair ainsi).
Route::get('/annuaire', [ListingController::class, 'index'])->name('annuaire.index');
Route::get('/annuaire/fiche/{listing:slug}', [ListingController::class, 'show'])->name('annuaire.show');
Route::get('/annuaire/{category:slug}', [ListingController::class, 'index'])->name('annuaire.category');

// Agenda (brief §6) — une seule route par slug : résout catégorie (dont
// "theatre", qui a ainsi sa propre URL comme demandé) puis événement.
Route::get('/agenda', [EventController::class, 'index'])->name('agenda.index');
Route::get('/agenda/{slug}', [EventController::class, 'bySlug'])->name('agenda.bySlug');

// Cinéma (brief §9).
Route::get('/cinema', [CinemaController::class, 'index'])->name('cinema.index');
Route::get('/cinema/films/{movie:slug}', [CinemaController::class, 'showMovie'])->name('cinema.movie');
Route::get('/cinema/salles/{cinema:slug}', [CinemaController::class, 'showCinema'])->name('cinema.salle');

// Les routes annonces/actualités/contact seront ajoutées aux phases 9-10.
