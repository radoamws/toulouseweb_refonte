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

        return view('cinema.movie', [
            'movie' => $movie,
            'screeningsByCinema' => $screeningsByCinema,
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

        return view('cinema.salle', [
            'cinema' => $cinema,
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
    protected function currentlyValid(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', now()))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()));
    }
}
