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
use App\Models\TransportLine;
use App\Services\Weather\OpenMeteoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Homepage repensée (brief §4) : slider administrable, contenus à la une par
 * domaine (actualités, agenda, cinéma, annuaire, annonces), catégories
 * importantes. Conçue pour maximiser navigation/découverte plutôt que
 * reproduire l'ancienne page 3-colonnes (voir TECHNICAL_DOCUMENTATION.md §6).
 */
class HomeController extends Controller
{
    public function index(Request $request, OpenMeteoService $weather): View
    {
        $page = Page::where('key', 'home')->first();

        // Vue de page (demande client, 15/09/2026, voir §44) — pas d'entité
        // précise pour la home.
        $this->recordPageView($request);

        return view('home', [
            'seo' => $page?->resolveSeo() ?? [],
            'slides' => Slider::activeOn('home')->with('placements')->get(),
            // Météo + qualité de l'air (brief roadmap "contenu", 14/10/2026,
            // demande client) — widget purement informatif, `null` si
            // Open-Meteo est indisponible (voir OpenMeteoService::current()),
            // jamais d'erreur remontée à la home pour ça.
            'weather' => $weather->current(),
            // Tri par date de publication la plus récente (demande client,
            // 12/09/2026) — `COALESCE` plutôt qu'un simple `latest('published_at')` :
            // un article publié sans `published_at` renseigné (rare, 1/234
            // en production) remonterait sinon systématiquement en tête de
            // liste (NULL trié comme la plus petite valeur en DESC sur
            // MySQL), peu importe son ancienneté réelle — repli sur
            // `created_at` dans ce cas précis.
            // Demande client (20/11/2026, voir §82) : "les news ne sont pas
            // à jour" + "prioriser les news publiés manuellement... et
            // validés" + "n'affichera que les news supérieures à la date
            // du jour". Une actu déjà publiée n'a jamais de date FUTURE —
            // confirmé avec le client qu'il s'agit en fait de fraîcheur
            // (publiée aujourd'hui/récemment), pas d'un filtre "date >
            // maintenant" qui viderait systématiquement la section.
            // Fenêtre glissante de 24h, cohérente avec le scraping
            // quotidien (`scrape:news`, 05h45) : au-delà, la section
            // resterait vide entre deux passages.
            'latestNews' => News::query()->published()
                ->whereRaw('COALESCE(published_at, created_at) >= ?', [now()->subDay()])
                // `source != 'scraped'` (soumission publique validée OU
                // saisie admin) toujours avant une actu scrapée, jamais
                // l'inverse — voir App\Services\Scraping\News\LeJournalToulousainDriver.
                ->orderByRaw("source = 'scraped' asc")
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->limit(4)->get(),
            // ⚠️ Bug réel trouvé et corrigé (20/11/2026, signalé par le
            // client : "Agenda & sorties" doit afficher des dates
            // supérieures à aujourd'hui) — confirmé en direct en production :
            // la section affichait "Obstinées" daté du 03 août 2026 alors
            // qu'on était le 20 novembre. `Event::scopeUpcoming()` (utilisé
            // tel quel sur /agenda) filtre correctement sur `end_date`, pas
            // sur `start_date` — un spectacle à residence longue durée
            // ("Obstinées", `start_date` = 03/08/2026, `end_date` =
            // 05/12/2026) y reste donc légitimement "à venir" tant que sa
            // dernière date n'est pas passée. Problème spécifique à CETTE
            // vignette d'accueil : `orderBy('start_date')` ASC fait
            // remonter en premier le spectacle qui a commencé le PLUS TÔT
            // (donc le plus ancien), avant les événements qui n'ont pas
            // encore commencé — l'inverse de l'effet "nouveauté" recherché
            // ici. `where('start_date', '>=', now())` restreint CETTE
            // requête aux événements pas encore commencés (947 en
            // production au moment du correctif, largement suffisant) —
            // volontairement PAS un changement de `scopeUpcoming()` lui-même
            // (/agenda doit continuer à lister aussi les spectacles en
            // cours de residence, eux bien "à venir" pour qui n'y est pas
            // encore allé).
            'upcomingEvents' => Event::query()->published()->upcoming()->where('start_date', '>=', now())
                ->with(['area', 'categories'])
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
            // Carrousel transports (brief roadmap "contenu", 14/10/2026,
            // demande client) — un slide par mode, compte réel de lignes
            // actives par type plutôt que des noms de lignes en dur (reste
            // juste même si l'import Tisséo change le détail des lignes,
            // voir ImportTisseoGtfs). Pas affiché du tout si aucune ligne
            // n'a encore été importée (pas de bloc vide/trompeur).
            'transportLineCounts' => TransportLine::where('is_active', true)
                ->selectRaw('type, count(*) as aggregate')
                ->groupBy('type')
                ->pluck('aggregate', 'type'),
        ]);
    }
}
