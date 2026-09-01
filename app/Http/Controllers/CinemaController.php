<?php

namespace App\Http\Controllers;

use App\Models\Cinema;
use App\Models\Movie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cinéma (brief §9). Alimenté par les données migrées (§10) ET par le
 * scraper AlloCiné (`scrape:cinema`/`AllocineDriver`, quotidien, vérifié en
 * direct le 25/08/2026 — 25/25 sources réelles, voir TECHNICAL_DOCUMENTATION.md
 * §13) qui tient à jour films/salles/horaires précis.
 */
class CinemaController extends Controller
{
    public function index(Request $request): View
    {
        $movies = Movie::query()
            ->whereHas('screenings', fn (Builder $q) => $this->currentlyValid($q))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->orderBy('title')
            ->paginate(24)
            ->withQueryString();

        $cinemas = Cinema::where('is_active', true)->orderBy('name')->get();

        return view('cinema.index', [
            'movies' => $movies,
            'cinemas' => $cinemas,
            'seo' => [],
        ]);
    }

    public function showMovie(Request $request, string $slug): View|\Illuminate\Http\RedirectResponse
    {
        $movie = Movie::where('slug', $slug)->first();
        if (! $movie) {
            return $this->redirectOrAbort($request->path());
        }

        $movie->load([
            'screenings' => fn ($q) => $this->currentlyValid($q)->with(['cinema', 'language', 'types', 'times']),
            'comments' => fn ($q) => $q->where('status', 'published')->latest(),
        ]);

        $screeningsByCinema = $movie->screenings->groupBy('cinema.name');

        // Maillage interne (brief §13, SEO/GEO) — autres films actuellement
        // à l'affiche, hors film courant.
        $related = Movie::query()
            ->whereHas('screenings', fn (Builder $q) => $this->currentlyValid($q))
            ->where('id', '!=', $movie->id)
            ->latest('release_date')
            ->limit(4)
            ->get();

        return view('cinema.movie', [
            'movie' => $movie,
            'screeningsByCinema' => $screeningsByCinema,
            'related' => $related,
            'seo' => $movie->resolveSeo(),
        ]);
    }

    public function showCinema(Request $request, string $slug): View|\Illuminate\Http\RedirectResponse
    {
        $cinema = Cinema::where('slug', $slug)->first();
        if (! $cinema) {
            return $this->redirectOrAbort($request->path());
        }

        $cinema->load([
            'screenings' => fn ($q) => $this->currentlyValid($q)->with(['movie', 'language', 'times']),
        ]);

        // Maillage interne (brief §13, SEO/GEO) — autres salles actives,
        // hors salle courante.
        $related = Cinema::where('is_active', true)
            ->where('id', '!=', $cinema->id)
            ->orderBy('name')
            ->limit(4)
            ->get();

        return view('cinema.salle', [
            'cinema' => $cinema,
            'related' => $related,
            'seo' => $cinema->resolveSeo(),
        ]);
    }

    /**
     * @param  Builder|Relation  $query  Un `Relation` (ex: HasMany) quand
     *                                   appelé depuis une closure de eager
     *                                   loading (`->load(['screenings' => ...])`),
     *                                   un `Builder` classique sinon
     *                                   (`whereHas`). Les deux exposent les
     *                                   mêmes méthodes `where`/`with` utilisées ici.
     */
    /**
     * ⚠️ Bug réel trouvé et corrigé (01/09/2026, signalé par le client :
     * scraping réussi — 25 films, milliers d'horaires — mais aucune séance
     * affichée en front). `screenings.start_date`/`end_date` sont des
     * colonnes `DATE` pures (pas `DATETIME`, voir migration
     * `adjust_cinema_schedule_columns`, et le commentaire de cast sur
     * `App\Models\Screening` — un bug voisin y avait déjà été documenté).
     * Comparer `end_date >= now()` compare donc une date normalisée à
     * minuit (ex. "2026-09-01 00:00:00") à l'heure COMPLÈTE actuelle
     * ("2026-09-01 19:13:54") : dès la première seconde après minuit, le
     * dernier jour de la fenêtre de programmation
     * (`AllocineDriver::currentProgrammingWeek()`) était donc déjà exclu —
     * en pratique, quasi INSTANTANÉMENT après chaque scraping.
     *
     * Fix : comparer à une chaîne `'Y-m-d'` nue (`now()->toDateString()`),
     * PAS un objet `Carbon`/une chaîne datetime complète — un objet Carbon
     * lié en paramètre de requête se sérialise en `'Y-m-d H:i:s'`, ce que
     * MySQL coerce silencieusement au bon résultat pour une colonne `DATE`
     * (d'où le bug resté invisible en test manuel superficiel), mais que
     * SQLite (moteur de test) compare en texte BRUT — `'2026-09-01'` est
     * lexicographiquement INFÉRIEUR à `'2026-09-01 00:00:00'` (chaîne plus
     * courte). Une chaîne date nue élimine l'ambiguïté sur les deux moteurs.
     */
    protected function currentlyValid(Builder|Relation $query): Builder|Relation
    {
        $today = now()->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today));
    }
}
