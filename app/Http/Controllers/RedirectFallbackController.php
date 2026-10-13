<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\MissedRedirect;
use App\Models\Movie;
use App\Models\News;
use App\Models\Redirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dernier recours pour toute URL qui ne correspond à aucune route : sert les
 * redirections 301 administrables (brief §15) avant d'abandonner sur un
 * vrai 404. Enregistré via `Route::fallback()` (routes/web.php), donc
 * seulement consulté après échec de toutes les routes explicites.
 *
 * Demande client (13/10/2026, rapport Search Console "Introuvable (404)",
 * ~1000 URLs) : en plus des redirections littérales administrables
 * (`redirects`, alimentée au coup par coup via l'admin/`MigrateRedirects`),
 * reconnaît quelques FORMES d'URL legacy confirmées (voir
 * `old/client-app/nuxt.config.js`) dont le préfixe ne correspond à AUCUNE
 * route actuelle — `Route::fallback()` ne les voit donc jamais entrer en
 * conflit avec une route vivante (contrairement à `/annuaire/fiche/{slug}`
 * par ex., déjà géré par `Controller::redirectOrAbort()`) :
 *   - /film/{slug}             -> /cinema/films/{slug}        (ancienne fiche film Nuxt)
 *   - /film/{slug}/salle       -> /cinema/films/{slug}#seances (ancienne page "où voir ce film ?")
 *   - /film/{slug}/commentaire -> /cinema/films/{slug}#avis    (ancienne page commentaires)
 *   - /news/{slug}             -> /actualites/{slug}           (ancien préfixe actualités)
 *   - /{...}/{slug-de-catégorie-existante} -> /annuaire/{slug}
 *     (ancienne arborescence annuaire imbriquée `/annuaire/cat/scat1/scat2`
 *     ET ses raccourcis thématiques type `/sports/{sous-catégorie}` —
 *     confirmés dans le legacy, voir TECHNICAL_DOCUMENTATION.md §9/§50 :
 *     seul le DERNIER segment porte l'information utile, la catégorie la
 *     plus précise de la chaîne)
 *
 * Volontairement une liste FERMÉE de formes confirmées, jamais une
 * redirection générique "1er segment inconnu -> deviner" : une redirection
 * vers une page qui n'existe pas (ou vers la mauvaise page) nuit plus au SEO
 * qu'un 404 propre. Chaque règle vérifie que la cible existe RÉELLEMENT
 * avant de rediriger — sinon le 404 (et son enregistrement dans
 * `missed_redirects`, toujours utile à `redirects:audit`) reste inchangé.
 * `film`/`news` traités par des règles dédiées (jamais par la règle
 * générique "dernier segment") pour qu'une fiche film/actualité
 * définitivement supprimée ne se fasse jamais rediriger à tort vers une
 * catégorie annuaire homonyme.
 *
 * ⚠️ Hors scope ici (confirmé par l'audit) : certaines URLs `/agenda/{slug}`
 * du rapport Search Console correspondent à des événements fusionnés/
 * supprimés depuis (dédoublonnage agenda, voir les commandes Dedupe...
 * et Reset... de app/Console/Commands/Migration/) sans trace fiable de
 * leur nouvelle cible. De plus, `/agenda/{slug}` correspond à la forme
 * d'une route VIVANTE (`EventController::bySlug`) — un tel 404 ne passe
 * donc jamais par CE contrôleur, voir plutôt
 * `Controller::redirectOrAbort()`. Laissées en 404 plutôt que de deviner
 * une redirection, conformément à la même règle ci-dessus.
 */
class RedirectFallbackController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        if (! $request->isMethod('GET')) {
            abort(404);
        }

        $path = '/'.ltrim($request->path(), '/');

        $redirect = Redirect::where('from_path', $path)->where('is_active', true)->first();

        if ($redirect) {
            $redirect->increment('hits_count');

            return redirect($redirect->to_path, $redirect->status_code);
        }

        if ($to = $this->resolveLegacyPattern($path)) {
            return redirect($to, 301);
        }

        MissedRedirect::record($path);

        abort(404);
    }

    private function resolveLegacyPattern(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        if ($segments === []) {
            return null;
        }

        return match ($segments[0]) {
            'film' => $this->resolveFilmPattern($segments),
            'news' => $this->resolveNewsPattern($segments),
            default => $this->resolveAnnuaireShortcutPattern($segments),
        };
    }

    /** @param list<string> $segments */
    private function resolveFilmPattern(array $segments): ?string
    {
        if (count($segments) < 2 || ! Movie::where('slug', $segments[1])->exists()) {
            return null;
        }

        return match ($segments[2] ?? null) {
            null => "/cinema/films/{$segments[1]}",
            'salle' => "/cinema/films/{$segments[1]}#seances",
            'commentaire' => "/cinema/films/{$segments[1]}#avis",
            default => null,
        };
    }

    /** @param list<string> $segments */
    private function resolveNewsPattern(array $segments): ?string
    {
        if (count($segments) !== 2) {
            return null;
        }

        return News::where('slug', $segments[1])->exists() ? "/actualites/{$segments[1]}" : null;
    }

    /** @param list<string> $segments */
    private function resolveAnnuaireShortcutPattern(array $segments): ?string
    {
        if (count($segments) < 2) {
            return null;
        }

        $last = end($segments);

        return Category::where('slug', $last)->exists() ? "/annuaire/{$last}" : null;
    }
}
