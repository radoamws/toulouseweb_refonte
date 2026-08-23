<?php

namespace App\Http\Controllers;

use App\Models\Cinema;
use App\Models\Movie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cinéma (brief §9). Le scraping temps réel (Phase 8, réécriture complète
 * de l'auto-update Pathé-Gaumont cassé côté legacy) n'est pas encore fait —
 * ces vues consomment les données migrées telles quelles (§10).
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

    public function showMovie(Movie $movie): View
    {
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

    public function showCinema(Cinema $cinema): View
    {
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
