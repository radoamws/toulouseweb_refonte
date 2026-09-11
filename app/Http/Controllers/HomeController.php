<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Classified;
use App\Models\Event;
use App\Models\Listing;
use App\Models\Movie;
use App\Models\News;
use App\Models\Page;
use App\Models\Slider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

/**
 * Homepage repensée (brief §4) : slider administrable, contenus à la une par
 * domaine (actualités, agenda, cinéma, annuaire, annonces), catégories
 * importantes. Conçue pour maximiser navigation/découverte plutôt que
 * reproduire l'ancienne page 3-colonnes (voir TECHNICAL_DOCUMENTATION.md §6).
 */
class HomeController extends Controller
{
    public function index(): View
    {
        $page = Page::where('key', 'home')->first();

        return view('home', [
            'seo' => $page?->resolveSeo() ?? [],
            'slides' => Slider::activeOn('home')->with('placements')->get(),
            'latestNews' => News::query()->published()->latest('published_at')->limit(4)->get(),
            'upcomingEvents' => Event::query()->published()->upcoming()->with(['area', 'categories'])
                ->orderBy('start_date')->limit(4)->get(),
            'featuredListings' => Listing::query()->where('status', 'published')->where('tier', 'paid')
                ->with('categories')->latest()->limit(6)->get(),
            // ⚠️ Bug réel trouvé et corrigé (11/09/2026, audit UI/UX) :
            // `whereHas('screenings')` sans filtre acceptait n'importe quel
            // film ayant EU une séance un jour, même terminée depuis des
            // années — vérifié en direct : la home proposait "The Fabelmans"
            // (2022) alors que /cinema affichait déjà "Aucun film à
            // l'affiche". Même scope que CinemaController::index() (déjà
            // correct), pour ne jamais promouvoir un film qui ne joue plus.
            'latestMovies' => Movie::query()
                ->whereHas('screenings', fn (Builder $q) => $q->currentlyValid())
                ->latest('release_date')->limit(6)->get(),
            'latestClassifieds' => Classified::query()->where('status', 'published')->latest()->limit(4)->get(),
            'topCategories' => Category::query()->where('level', 0)->where('is_active', true)
                ->orderBy('order')->limit(8)->get(),
        ]);
    }
}
