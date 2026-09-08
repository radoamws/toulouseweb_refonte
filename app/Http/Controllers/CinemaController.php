<?php

namespace App\Http\Controllers;

use App\Models\Cinema;
use App\Models\Movie;
use Illuminate\Database\Eloquent\Builder;
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
            ->whereHas('screenings', fn (Builder $q) => $q->currentlyValid())
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
            'screenings' => fn ($q) => $q->currentlyValid()->with(['cinema', 'language', 'types', 'times']),
            'comments' => fn ($q) => $q->where('status', 'published')->latest(),
        ]);

        $screeningsByCinema = $movie->screenings->groupBy('cinema.name');

        // Maillage interne (brief §13, SEO/GEO) — autres films actuellement
        // à l'affiche, hors film courant.
        $related = Movie::query()
            ->whereHas('screenings', fn (Builder $q) => $q->currentlyValid())
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
            'screenings' => fn ($q) => $q->currentlyValid()->with(['movie', 'language', 'times']),
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

    // Le filtre "séance actuellement valide" (bug DATE-vs-DATETIME trouvé et
    // corrigé ici le 01/09/2026, voir TECHNICAL_DOCUMENTATION.md §13) vit
    // maintenant sur `Screening::scopeCurrentlyValid()` — extrait le
    // 07/09/2026 (audit SEO/perf final) après avoir trouvé que
    // `GenerateSitemap` dupliquait cette même logique SANS le correctif
    // (comparait à `now()`, pas `now()->toDateString()`), un bug réel qui
    // pouvait faire manquer des films au sitemap dès la première seconde
    // après minuit — voir docblock de `Screening::scopeCurrentlyValid()`.
}
