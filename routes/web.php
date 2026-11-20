<?php

use App\Http\Controllers\CinemaController;
use App\Http\Controllers\ClassifiedController;
use App\Http\Controllers\ClickTrackingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EditorAuthController;
use App\Http\Controllers\EditorDashboardController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsletterSubscriptionController;
use App\Http\Controllers\RedirectFallbackController;
use App\Http\Controllers\TransportController;
use App\Http\Controllers\WebCronController;
use App\Http\Controllers\WebCronTransportController;
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
// Espace client (demande client 16/11/2026, étape 3) — `mon-espace` avant
// `{categorySlug}` (wildcard) pour ne pas être intercepté, même piège que
// pour /annuaire/deposer ci-dessus.
Route::get('/annuaire/mon-espace/{token}', [ListingController::class, 'editSpace'])->name('annuaire.editSpace');
Route::put('/annuaire/mon-espace/{token}', [ListingController::class, 'updateEditSpace'])->middleware('throttle:10,1')->name('annuaire.updateEditSpace');
Route::get('/annuaire/{categorySlug}', [ListingController::class, 'index'])->name('annuaire.category');

// Agenda (brief §6) — une seule route par slug : résout catégorie (dont
// "theatre", qui a ainsi sa propre URL comme demandé) puis événement.
// `proposer` avant `{slug}` (wildcard) pour ne pas être intercepté — même
// piège que pour /annuaire/deposer et /annonces/deposer.
Route::get('/agenda', [EventController::class, 'index'])->name('agenda.index');
Route::get('/agenda/proposer', [EventController::class, 'create'])->name('agenda.create');
Route::post('/agenda/proposer', [EventController::class, 'store'])->middleware('throttle:5,1')->name('agenda.store');
// Espace client (demande client 16/11/2026, étape 2) — `mon-espace` avant
// `{slug}` (wildcard) pour ne pas être intercepté, même piège que pour
// /agenda/proposer ci-dessus.
Route::get('/agenda/mon-espace/{token}', [EventController::class, 'editSpace'])->name('agenda.editSpace');
Route::put('/agenda/mon-espace/{token}', [EventController::class, 'updateEditSpace'])->middleware('throttle:10,1')->name('agenda.updateEditSpace');
Route::get('/agenda/{slug}', [EventController::class, 'bySlug'])->name('agenda.bySlug');

// Cinéma (brief §9).
Route::get('/cinema', [CinemaController::class, 'index'])->name('cinema.index');
Route::get('/cinema/films/{slug}', [CinemaController::class, 'showMovie'])->name('cinema.movie');
// Avis sur un film (demande client, 19/09/2026) — même throttle que les
// autres formulaires publics (agenda.store, annonces.store...).
Route::post('/cinema/films/{slug}/avis', [CinemaController::class, 'storeComment'])->middleware('throttle:5,1')->name('cinema.movie.comment');
Route::get('/cinema/salles/{slug}', [CinemaController::class, 'showCinema'])->name('cinema.salle');
// Panorama (demande client, 05/10/2026 — restaure la page dédiée du
// legacy après un 1er essai en simple bloc d'en-tête sur /cinema, voir
// TECHNICAL_DOCUMENTATION.md §49/§68) : page et SEO distincts de /cinema et
// /cinema/salles/{slug}, AVANT /cinema/{slug} générique s'il en existait un
// (aucun ici, mais convention de ce fichier : plus spécifique avant).
Route::get('/cinema/panorama', [CinemaController::class, 'panorama'])->name('cinema.panorama');

// Actualités — même pattern catégorie/article que l'agenda. `proposer`
// avant `{slug}` (wildcard) pour ne pas être intercepté — même piège que
// pour /annuaire/deposer, /agenda/proposer et /annonces/deposer.
Route::get('/actualites', [NewsController::class, 'index'])->name('actualites.index');
Route::get('/actualites/proposer', [NewsController::class, 'create'])->name('actualites.create');
Route::post('/actualites/proposer', [NewsController::class, 'store'])->middleware('throttle:5,1')->name('actualites.store');
// Espace client (demande client 16/11/2026, étape 2) — `mon-espace` avant
// `{slug}` (wildcard) pour ne pas être intercepté, même piège que pour
// /actualites/proposer ci-dessus.
Route::get('/actualites/mon-espace/{token}', [NewsController::class, 'editSpace'])->name('actualites.editSpace');
Route::put('/actualites/mon-espace/{token}', [NewsController::class, 'updateEditSpace'])->middleware('throttle:10,1')->name('actualites.updateEditSpace');
Route::get('/actualites/{slug}', [NewsController::class, 'bySlug'])->name('actualites.bySlug');

// Annonces (brief §8). `deposer` avant `{slug}` pour ne pas être intercepté
// par la résolution catégorie/annonce.
Route::get('/annonces', [ClassifiedController::class, 'index'])->name('annonces.index');
Route::get('/annonces/deposer', [ClassifiedController::class, 'create'])->name('annonces.create');
Route::post('/annonces', [ClassifiedController::class, 'store'])->middleware('throttle:5,1')->name('annonces.store');
// Espace client (brief, demande client 16/11/2026) — `mon-espace` avant
// `{slug}` (wildcard) pour ne pas être intercepté, même piège que pour
// /annonces/deposer ci-dessus.
Route::get('/annonces/mon-espace/{token}', [ClassifiedController::class, 'editSpace'])->name('annonces.editSpace');
Route::put('/annonces/mon-espace/{token}', [ClassifiedController::class, 'updateEditSpace'])->middleware('throttle:10,1')->name('annonces.updateEditSpace');
Route::get('/annonces/{slug}', [ClassifiedController::class, 'bySlug'])->name('annonces.bySlug');

// Comptes "éditeur" (demande client, 17/11/2026) — gèrent les publications
// du déposant (annonces/agenda/actualités/annuaire), voir
// App\Http\Controllers\EditorAuthController et TECHNICAL_DOCUMENTATION.md.
// Guard dédié `editor` (config/auth.php), séparé de l'auth admin Filament.
Route::middleware('guest:editor')->group(function () {
    // Inscription libre (demande client, 17/11/2026) — AVANT `{token}`
    // ci-dessous (forme sans segment supplémentaire, pas de réelle
    // ambiguïté de toute façon, mais même convention que le reste de ce
    // fichier : le plus spécifique d'abord).
    Route::get('/mon-compte/inscription', [EditorAuthController::class, 'showSignup'])->name('editeur.inscription.libre');
    Route::post('/mon-compte/inscription', [EditorAuthController::class, 'signup'])->middleware('throttle:5,1')->name('editeur.inscription.libre.store');
    Route::get('/mon-compte/inscription/{token}', [EditorAuthController::class, 'invitation'])->name('editeur.inscription');
    Route::post('/mon-compte/inscription/{token}', [EditorAuthController::class, 'register'])->middleware('throttle:5,1')->name('editeur.inscription.store');
    Route::get('/mon-compte/connexion', [EditorAuthController::class, 'showLogin'])->name('editeur.connexion');
    Route::post('/mon-compte/connexion', [EditorAuthController::class, 'login'])->middleware('throttle:5,1')->name('editeur.connexion.store');
    Route::get('/mon-compte/mot-de-passe-oublie', [EditorAuthController::class, 'showForgotPassword'])->name('editeur.mot-de-passe.oublie');
    Route::post('/mon-compte/mot-de-passe-oublie', [EditorAuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('editeur.mot-de-passe.oublie.store');
    Route::get('/mon-compte/reinitialiser-mot-de-passe/{token}', [EditorAuthController::class, 'showResetPassword'])->name('editeur.mot-de-passe.reinitialiser');
    Route::post('/mon-compte/reinitialiser-mot-de-passe', [EditorAuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('editeur.mot-de-passe.reinitialiser.store');
});
Route::middleware('auth:editor')->group(function () {
    Route::post('/mon-compte/deconnexion', [EditorAuthController::class, 'logout'])->name('editeur.deconnexion');
    Route::get('/mon-compte', [EditorDashboardController::class, 'index'])->name('editeur.mes-publications');

    // Édition à champs complets (demande client, point 4) — par opposition
    // aux liens par jeton /annonces/mon-espace/{token} etc. (étape
    // antérieure, toujours valables pour les liens déjà envoyés, voir
    // ClassifiedController::editSpace()).
    Route::get('/mon-compte/annonces/{classified}/modifier', [ClassifiedController::class, 'editOwned'])->name('editeur.annonces.modifier');
    Route::put('/mon-compte/annonces/{classified}/modifier', [ClassifiedController::class, 'updateOwned'])->name('editeur.annonces.modifier.store');
    Route::get('/mon-compte/agenda/{event}/modifier', [EventController::class, 'editOwned'])->name('editeur.agenda.modifier');
    Route::put('/mon-compte/agenda/{event}/modifier', [EventController::class, 'updateOwned'])->name('editeur.agenda.modifier.store');
    Route::get('/mon-compte/actualites/{news}/modifier', [NewsController::class, 'editOwned'])->name('editeur.actualites.modifier');
    Route::put('/mon-compte/actualites/{news}/modifier', [NewsController::class, 'updateOwned'])->name('editeur.actualites.modifier.store');
    Route::get('/mon-compte/annuaire/{listing}/modifier', [ListingController::class, 'editOwned'])->name('editeur.annuaire.modifier');
    Route::put('/mon-compte/annuaire/{listing}/modifier', [ListingController::class, 'updateOwned'])->name('editeur.annuaire.modifier.store');
});

// Contact (brief §11).
Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');

// Lignes de transport Tisséo (brief roadmap "contenu", 14/10/2026) — voir
// App\Console\Commands\ImportTisseoGtfs et TransportController.
Route::get('/transports', [TransportController::class, 'index'])->name('transports.index');
// Avant {slug} : sinon "itineraire"/"autocompletion" seraient capturés comme un slug de ligne.
Route::get('/transports/itineraire', [TransportController::class, 'journey'])->name('transports.journey');
// Autocomplétion en direct (arrêts + rues, demande client 03/11/2026 — "un
// peu comme sur tisseo.fr") : throttle dédié, appelée à chaque frappe
// (debounce côté JS) et interroge l'API BAN en repli, voir
// TransportController::autocomplete().
Route::get('/transports/autocompletion', [TransportController::class, 'autocomplete'])
    ->middleware('throttle:60,1')
    ->name('transports.autocomplete');
Route::get('/transports/{slug}', [TransportController::class, 'show'])->name('transports.show');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact.store');

// Newsletter (demande client, 12/09/2026, voir TECHNICAL_DOCUMENTATION.md §36).
Route::post('/newsletter/inscription', [NewsletterSubscriptionController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('newsletter.subscribe');
Route::get('/newsletter/desinscription/{token}', [NewsletterSubscriptionController::class, 'unsubscribe'])
    ->name('newsletter.unsubscribe');

// Déclencheur WebCron (hébergement mutualisé Infomaniak, pas de crontab
// serveur — voir App\Console\Commands\RunWebCron et
// TECHNICAL_DOCUMENTATION.md §27). Le jeton dans l'URL est la seule
// protection ; throttle en plus par précaution.
Route::get('/webcron/{token}', WebCronController::class)
    ->middleware('throttle:10,1')
    ->name('webcron.run');

// 2e WebCron séparé (brief roadmap "contenu", 16/10/2026) — voir docblock de
// WebCronTransportController pour pourquoi il ne doit SURTOUT PAS être
// fusionné avec le précédent.
Route::get('/webcron-transport/{token}', WebCronTransportController::class)
    ->middleware('throttle:10,1')
    ->name('webcron.transport');

// Redirections 301 administrables (brief §15) — dernier recours, seulement
// consulté quand aucune route ci-dessus ne correspond.
Route::fallback(RedirectFallbackController::class);
