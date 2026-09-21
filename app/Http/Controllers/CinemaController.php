<?php

namespace App\Http\Controllers;

use App\Models\Cinema;
use App\Models\Movie;
use App\Models\Page;
use App\Rules\Recaptcha;
use App\Support\AdminNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
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

        // "Les plus commentés" (demande client, 19/09/2026 — bonne pratique
        // des sites de cinéma type AlloCiné) : agrégats calculés en base
        // (withCount/withAvg) pour éviter un N+1 sur `publishedComments`,
        // limité aux films actuellement à l'affiche.
        $mostCommented = Movie::query()
            ->whereHas('screenings', fn (Builder $q) => $q->currentlyValid())
            // whereHas plutôt que having('published_comments_count', '>', 0) :
            // HAVING sur un alias de subquery n'est pas portable (SQLite,
            // utilisé en test, le refuse — "HAVING clause on a non-aggregate
            // query" — contrairement à MySQL qui l'accepte).
            ->whereHas('publishedComments')
            ->withCount('publishedComments')
            ->withAvg('publishedComments', 'rating')
            ->orderByDesc('published_comments_count')
            ->limit(6)
            ->get();

        $this->recordPageView($request);

        return view('cinema.index', [
            'movies' => $movies,
            'cinemas' => $cinemas,
            'mostCommented' => $mostCommented,
            // "Panorama" de la semaine (demande client, 19/09/2026, exemple
            // donné : "du 16 Septembre 2026 au 22 septembre 2026" — un
            // mercredi à mardi, PAS la semaine civile lundi→dimanche du
            // calendrier agenda) : convention de l'industrie du cinéma en
            // France, où les nouveaux films sortent le mercredi.
            'weekStart' => now()->startOfWeek(\Carbon\Carbon::WEDNESDAY),
            'weekEnd' => now()->endOfWeek(\Carbon\Carbon::TUESDAY),
            // Fiche "menu" migrée (bug réel corrigé le 08/09/2026, voir
            // docblock équivalent sur EventController::renderIndex()) —
            // '/cinema' n'avait ici aucun repli du tout (toujours []).
            'seo' => Page::where('key', 'seo-menu-cinema')->first()?->resolveSeo() ?? [],
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
            'publishedComments' => fn ($q) => $q->latest(),
        ]);

        $screeningsByCinema = $movie->screenings->groupBy('cinema.name');
        $commentsCount = $movie->publishedComments->count();
        $averageRating = $movie->publishedComments->whereNotNull('rating')->avg('rating');

        // Maillage interne (brief §13, SEO/GEO) — autres films actuellement
        // à l'affiche, hors film courant.
        $related = Movie::query()
            ->whereHas('screenings', fn (Builder $q) => $q->currentlyValid())
            ->where('id', '!=', $movie->id)
            ->latest('release_date')
            ->limit(4)
            ->get();

        $this->recordPageView($request, 'movie', $movie->id);

        return view('cinema.movie', [
            'movie' => $movie,
            'screeningsByCinema' => $screeningsByCinema,
            'commentsCount' => $commentsCount,
            'averageRating' => $averageRating,
            'related' => $related,
            'seo' => $movie->resolveSeo(),
        ]);
    }

    /**
     * Avis sur un film (demande client, 19/09/2026) — même workflow de
     * modération STRICT que les annonces/événements proposés (voir
     * App\Http\Controllers\ClassifiedController::store()) : jamais de
     * publication automatique, `status` toujours forcé à `pending` ici,
     * seul un admin (MovieCommentResource) le fait passer à `published`.
     * Pas de compte visiteur sur ce site (voir docblock de MovieComment) —
     * soumission anonyme + honeypot + throttle (route `cinema.movie.comment`),
     * mêmes protections anti-spam que les autres formulaires publics.
     */
    public function storeComment(Request $request, string $slug): RedirectResponse
    {
        $movie = Movie::where('slug', $slug)->first();
        if (! $movie) {
            return $this->redirectOrAbort($request->path());
        }

        $validated = $request->validate([
            'author_name' => ['required', 'string', 'max:255'],
            'author_email' => ['required', 'email', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['required', 'string', 'max:2000'],
            // Honeypot anti-spam (même convention que les autres formulaires
            // publics, voir ClassifiedController::store()).
            'website' => ['size:0'],
            'recaptcha_token' => Recaptcha::rules('movie_comment'),
        ]);

        $comment = $movie->comments()->create([
            'author_name' => $validated['author_name'],
            'author_email' => $validated['author_email'],
            'rating' => $validated['rating'],
            'body' => $validated['body'],
            'status' => 'pending', // jamais autre chose ici
        ]);

        AdminNotifier::send(
            'Nouvel avis film à valider',
            [
                'Film' => $movie->title,
                'Auteur' => $comment->author_name,
                'Note' => $comment->rating.'/5',
            ],
            route('filament.admin.resources.movie-comments.edit', $comment),
            'Valider ou refuser cet avis',
        );

        return redirect()
            ->route('cinema.movie', $movie->slug)
            ->with('status', 'Merci, votre avis a bien été reçu et sera publié après validation par notre équipe.');
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

        $this->recordPageView($request, 'cinema', $cinema->id);

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
