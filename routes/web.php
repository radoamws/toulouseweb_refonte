<?php

use App\Http\Controllers\CinemaController;
use App\Http\Controllers\ClassifiedController;
use App\Http\Controllers\ClickTrackingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\RedirectFallbackController;
use App\Http\Controllers\WebCronController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class.'@index')->name('home');

// GEO/AI Search (brief §13) — voir docblock de LlmsTxtController.
Route::get('/llms.txt', LlmsTxtController::class)->name('llms-txt');

// Tracking de clics générique (voir TECHNICAL_DOCUMENTATION.md §9) —
// appelable depuis n'importe quel composant public. Throttle pour éviter
// l'abus, pas de CSRF requis (appelé aussi depuis des liens sortants).
Route::post('/track-click', ClickTrackingController::class)
    ->middleware('throttle:60,1')
    ->name('track-click');

// Annuaire (brief §5). `fiche` avant `{categorySlug}` pour éviter toute
// ambiguïté (chemins à segments différents, mais plus clair ainsi).
// Slugs en paramètres simples (pas de route-model-binding) : voir docblock
// de ListingController pour la raison (redirections 301 legacy, brief §15).
Route::get('/annuaire', [ListingController::class, 'index'])->name('annuaire.index');
Route::get('/annuaire/fiche/{slug}', [ListingController::class, 'show'])->name('annuaire.show');
// `deposer` avant `{categorySlug}` (wildcard) pour ne pas être intercepté —
// même piège que pour /annonces, voir commentaire plus bas.
Route::get('/annuaire/deposer', [ListingController::class, 'create'])->name('annuaire.create');
Route::post('/annuaire/deposer', [ListingController::class, 'store'])->middleware('throttle:5,1')->name('annuaire.store');
Route::get('/annuaire/{categorySlug}', [ListingController::class, 'index'])->name('annuaire.category');

// Agenda (brief §6) — une seule route par slug : résout catégorie (dont
// "theatre", qui a ainsi sa propre URL comme demandé) puis événement.
// `proposer` avant `{slug}` (wildcard) pour ne pas être intercepté — même
// piège que pour /annuaire/deposer et /annonces/deposer.
Route::get('/agenda', [EventController::class, 'index'])->name('agenda.index');
Route::get('/agenda/proposer', [EventController::class, 'create'])->name('agenda.create');
Route::post('/agenda/proposer', [EventController::class, 'store'])->middleware('throttle:5,1')->name('agenda.store');
Route::get('/agenda/{slug}', [EventController::class, 'bySlug'])->name('agenda.bySlug');

// Cinéma (brief §9).
Route::get('/cinema', [CinemaController::class, 'index'])->name('cinema.index');
Route::get('/cinema/films/{slug}', [CinemaController::class, 'showMovie'])->name('cinema.movie');
Route::get('/cinema/salles/{slug}', [CinemaController::class, 'showCinema'])->name('cinema.salle');

// Actualités — même pattern catégorie/article que l'agenda. `proposer`
// avant `{slug}` (wildcard) pour ne pas être intercepté — même piège que
// pour /annuaire/deposer, /agenda/proposer et /annonces/deposer.
Route::get('/actualites', [NewsController::class, 'index'])->name('actualites.index');
Route::get('/actualites/proposer', [NewsController::class, 'create'])->name('actualites.create');
Route::post('/actualites/proposer', [NewsController::class, 'store'])->middleware('throttle:5,1')->name('actualites.store');
Route::get('/actualites/{slug}', [NewsController::class, 'bySlug'])->name('actualites.bySlug');

// Annonces (brief §8). `deposer` avant `{slug}` pour ne pas être intercepté
// par la résolution catégorie/annonce.
Route::get('/annonces', [ClassifiedController::class, 'index'])->name('annonces.index');
Route::get('/annonces/deposer', [ClassifiedController::class, 'create'])->name('annonces.create');
Route::post('/annonces', [ClassifiedController::class, 'store'])->middleware('throttle:5,1')->name('annonces.store');
Route::get('/annonces/{slug}', [ClassifiedController::class, 'bySlug'])->name('annonces.bySlug');

// Contact (brief §11).
Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact.store');

// Déclencheur WebCron (hébergement mutualisé Infomaniak, pas de crontab
// serveur — voir App\Console\Commands\RunWebCron et
// TECHNICAL_DOCUMENTATION.md §27). Le jeton dans l'URL est la seule
// protection ; throttle en plus par précaution.
Route::get('/webcron/{token}', WebCronController::class)
    ->middleware('throttle:10,1')
    ->name('webcron.run');

// Redirections 301 administrables (brief §15) — dernier recours, seulement
// consulté quand aucune route ci-dessus ne correspond.
Route::fallback(RedirectFallbackController::class);
